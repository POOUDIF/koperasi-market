<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pesanan. Setiap transisi status memakai UPDATE ... WHERE status = <asal>
 * + cek affected_rows — dua aksi bersamaan tidak bisa sama-sama berhasil.
 */
class Order_model extends MY_Model {

    const COLS = 'o.id, o.order_number, o.buyer_customer_id, o.store_id, o.status, o.subtotal, o.shipping_fee, o.total,
                  o.recipient_name, o.recipient_phone, o.shipping_address, o.buyer_note, o.tracking_number,
                  o.payment_method, o.payment_intent_id, o.payment_attempt, o.payout_action, o.payout_status,
                  o.cancel_reason, o.cancelled_by, o.paid_at, o.shipped_at, o.completed_at, o.cancelled_at,
                  o.created_at, o.updated_at';

    public function atomic_public(callable $fn) {
        return $this->atomic(function () use ($fn) { return $fn($this); });
    }

    /* ------------------------------------------------------------ checkout */

    /**
     * Buat pesanan dari keranjang satu toko. WAJIB dipanggil di dalam atomic.
     * Stok dikurangi dengan UPDATE bersyarat — tidak pernah oversell walau
     * dua pembeli checkout produk terakhir bersamaan.
     */
    public function create_from_cart($customer_id, $store_id, array $shipping) {
        $items = $this->q(
            "SELECT ci.product_id, ci.qty, p.name, p.price, p.member_price, p.stock, p.status
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
              WHERE ci.customer_id = ? AND p.store_id = ?
              ORDER BY p.id
                FOR UPDATE", array((int) $customer_id, (int) $store_id))->result_array();

        if (empty($items)) { throw Api_exception::cartEmpty(); }

        $subtotal = '0';
        $lines = array();
        foreach ($items as $it) {
            if ($it['status'] !== 'active') { throw Api_exception::productUnavailable($it['name']); }

            $this->q("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?",
                array((int) $it['qty'], (int) $it['product_id'], (int) $it['qty']));
            if ($this->db->affected_rows() < 1) { throw Api_exception::outOfStock($it['name']); }

            // Pembayaran Koperasi Pay hanya untuk anggota → harga anggota berlaku bila ada.
            $unit = $it['member_price'] !== NULL ? $it['member_price'] : $it['price'];
            $line = Money::mul($unit, (string) (int) $it['qty']);
            $subtotal = Money::add($subtotal, $line);
            $lines[] = array($it['product_id'], $it['name'], Money::norm($unit), (int) $it['qty'], $line);
        }

        $number = 'MKT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $this->q(
            "INSERT INTO orders (order_number, buyer_customer_id, store_id, subtotal, shipping_fee, total,
                                 recipient_name, recipient_phone, shipping_address, buyer_note)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)",
            array($number, (int) $customer_id, (int) $store_id, $subtotal, $subtotal,
                  $shipping['recipient_name'], $shipping['recipient_phone'], $shipping['shipping_address'], $shipping['buyer_note']));
        $order_id = (int) $this->db->insert_id();

        foreach ($lines as $l) {
            $this->q("INSERT INTO order_items (order_id, product_id, product_name, unit_price, qty, line_total) VALUES (?, ?, ?, ?, ?, ?)",
                array($order_id, $l[0], $l[1], $l[2], $l[3], $l[4]));
        }
        $this->q("DELETE ci FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.customer_id = ? AND p.store_id = ?",
            array((int) $customer_id, (int) $store_id));

        return $order_id;
    }

    /* --------------------------------------------------------------- baca */

    public function find($id) {
        return $this->row("SELECT " . self::COLS . " FROM orders o WHERE o.id = ? LIMIT 1", array((int) $id));
    }

    public function find_by_intent($intent_id) {
        return $this->row("SELECT " . self::COLS . " FROM orders o WHERE o.payment_intent_id = ? LIMIT 1", array((string) $intent_id));
    }

    public function items($order_id) {
        return array_map(function ($i) {
            return array(
                'product_id'   => (int) $i['product_id'],
                'product_name' => $i['product_name'],
                'unit_price'   => Money::out($i['unit_price']),
                'qty'          => (int) $i['qty'],
                'line_total'   => Money::out($i['line_total']),
            );
        }, $this->q("SELECT product_id, product_name, unit_price, qty, line_total FROM order_items WHERE order_id = ? ORDER BY id",
            array((int) $order_id))->result_array());
    }

    /** @param array $filter buyer_customer_id | store_id | (kosong = semua, admin) */
    public function list_paged(array $filter, $status, $limit, $offset) {
        list($where, $binds) = $this->filter_sql($filter, $status);
        $rows = $this->q(
            "SELECT " . self::COLS . ", s.name AS store_name, s.slug AS store_slug, c.name AS buyer_name
               FROM orders o JOIN stores s ON s.id = o.store_id JOIN customers c ON c.id = o.buyer_customer_id
              WHERE {$where} ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?",
            array_merge($binds, array((int) $limit, (int) $offset)))->result_array();
        return array_map(array($this, 'shape'), $rows);
    }

    public function count(array $filter, $status) {
        list($where, $binds) = $this->filter_sql($filter, $status);
        return (int) $this->row("SELECT COUNT(*) AS c FROM orders o WHERE {$where}", $binds)['c'];
    }

    /* ------------------------------------------------------------ transisi */

    public function set_payment_intent($id, $intent_id, $attempt) {
        $this->q("UPDATE orders SET payment_intent_id = ?, payment_attempt = ? WHERE id = ? AND status = 'pending_payment'",
            array($intent_id, (int) $attempt, (int) $id));
    }

    public function clear_payment_intent($id, $intent_id) {
        $this->q("UPDATE orders SET payment_intent_id = NULL WHERE id = ? AND payment_intent_id = ? AND status = 'pending_payment'",
            array((int) $id, (string) $intent_id));
    }

    public function mark_paid_by_intent($intent_id) {
        $this->q("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE payment_intent_id = ? AND status = 'pending_payment'",
            array((string) $intent_id));
        return $this->db->affected_rows() > 0;
    }

    public function mark_shipped($id, $store_id, $tracking) {
        $this->q("UPDATE orders SET status = 'shipped', shipped_at = NOW(), tracking_number = ? WHERE id = ? AND store_id = ? AND status = 'paid'",
            array($tracking, (int) $id, (int) $store_id));
        return $this->db->affected_rows() > 0;
    }

    /** shipped → completed + jadwalkan settle ke penjual. */
    public function mark_completed($id) {
        $this->q("UPDATE orders SET status = 'completed', completed_at = NOW(), payout_action = 'settle', payout_status = 'pending'
                   WHERE id = ? AND status = 'shipped'", array((int) $id));
        return $this->db->affected_rows() > 0;
    }

    /**
     * Batalkan + kembalikan stok, dalam satu transaction.
     * Dari 'paid' otomatis menjadwalkan refund ke pembeli.
     * @return bool
     */
    public function cancel($id, array $from_statuses, $by, $reason) {
        return $this->atomic(function () use ($id, $from_statuses, $by, $reason) {
            $o = $this->row("SELECT id, status FROM orders WHERE id = ? FOR UPDATE", array((int) $id));
            if ($o === NULL || ! in_array($o['status'], $from_statuses, TRUE)) { return FALSE; }

            $refund = $o['status'] === 'paid';
            $this->q(
                "UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?,
                        payout_action = IF(?, 'refund', payout_action), payout_status = IF(?, 'pending', payout_status)
                  WHERE id = ?", array($by, substr((string) $reason, 0, 255), $refund ? 1 : 0, $refund ? 1 : 0, (int) $id));

            foreach ($this->q("SELECT product_id, qty FROM order_items WHERE order_id = ?", array((int) $id))->result_array() as $it) {
                $this->q("UPDATE products SET stock = stock + ? WHERE id = ?", array((int) $it['qty'], (int) $it['product_id']));
            }
            return TRUE;
        });
    }

    /** Pembayaran terkonfirmasi SETELAH pesanan dibatalkan (balapan) → wajib refund. */
    public function schedule_refund_if_cancelled($intent_id) {
        $this->q("UPDATE orders SET payout_action = 'refund', payout_status = 'pending', paid_at = COALESCE(paid_at, NOW())
                   WHERE payment_intent_id = ? AND status = 'cancelled' AND payout_status = 'none'", array((string) $intent_id));
        return $this->db->affected_rows() > 0;
    }

    public function payout_done($id) {
        $this->q("UPDATE orders SET payout_status = 'done', payout_error = NULL WHERE id = ?", array((int) $id));
    }

    public function payout_error($id, $error, $give_up) {
        $this->q("UPDATE orders SET payout_error = ?, payout_status = IF(?, 'failed', payout_status) WHERE id = ?",
            array(substr((string) $error, 0, 255), $give_up ? 1 : 0, (int) $id));
    }

    /* ----------------------------------------------------------- pemeliharaan */

    public function pending_payouts($limit = 50) {
        return $this->q("SELECT " . self::COLS . " FROM orders o WHERE o.payout_status = 'pending' ORDER BY o.id LIMIT ?", array((int) $limit))->result_array();
    }

    public function stale_unpaid($hours, $limit = 50) {
        return $this->q("SELECT " . self::COLS . " FROM orders o
                          WHERE o.status = 'pending_payment' AND o.created_at < NOW() - INTERVAL ? HOUR ORDER BY o.id LIMIT ?",
            array((int) $hours, (int) $limit))->result_array();
    }

    public function pending_with_intent($limit = 50) {
        return $this->q("SELECT " . self::COLS . " FROM orders o
                          WHERE o.status = 'pending_payment' AND o.payment_intent_id IS NOT NULL ORDER BY o.id LIMIT ?",
            array((int) $limit))->result_array();
    }

    public function overdue_shipped($days, $limit = 50) {
        return $this->q("SELECT " . self::COLS . " FROM orders o
                          WHERE o.status = 'shipped' AND o.shipped_at < NOW() - INTERVAL ? DAY ORDER BY o.id LIMIT ?",
            array((int) $days, (int) $limit))->result_array();
    }

    public function record_webhook_event($event_id, $type) {
        $ok = $this->db->query("INSERT INTO webhook_events (event_id, event_type) VALUES (?, ?)", array($event_id, $type));
        if ($ok === FALSE) {
            if ($this->is_unique_violation()) { return FALSE; }
            throw Api_exception::server();
        }
        return TRUE;
    }

    public function shape(array $o) {
        foreach (array('id', 'buyer_customer_id', 'store_id', 'payment_attempt') as $k) {
            $o[$k] = (int) $o[$k];
        }
        foreach (array('subtotal', 'shipping_fee', 'total') as $k) {
            $o[$k] = Money::out($o[$k]);
        }
        return $o;
    }

    private function filter_sql(array $filter, $status) {
        $where = array('1 = 1');
        $binds = array();
        if (isset($filter['buyer_customer_id'])) { $where[] = 'o.buyer_customer_id = ?'; $binds[] = (int) $filter['buyer_customer_id']; }
        if (isset($filter['store_id']))          { $where[] = 'o.store_id = ?';          $binds[] = (int) $filter['store_id']; }
        if ($status !== '' && $status !== NULL)  { $where[] = 'o.status = ?';            $binds[] = $status; }
        return array(implode(' AND ', $where), $binds);
    }
}

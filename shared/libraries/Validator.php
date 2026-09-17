<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Padanan tag `binding:` Gin (§10.8).
 *
 * Melempar 400 pada pelanggaran PERTAMA, meniru perilaku ShouldBindJSON
 * yang berhenti di error pertama.
 */
class Validator {

    /**
     * @param  array $body  payload JSON hasil decode
     * @param  array $rules ['field' => ['required', 'min:8', ...]]
     * @return array        nilai yang sudah dibersihkan & dikonversi tipe
     * @throws Api_exception
     */
    public function check(array $body, array $rules) {
        $out = array();

        foreach ($rules as $field => $set) {
            $val      = $body[$field] ?? NULL;
            $required = in_array('required', $set, TRUE);

            if (is_string($val)) { $val = trim($val); }

            if ($val === NULL || $val === '') {
                if ($required) {
                    throw Api_exception::badRequest("field '{$field}' wajib diisi");
                }
                // Opsional & kosong → default string kosong, lewati aturan lain.
                $out[$field] = '';
                continue;
            }

            foreach ($set as $rule) {
                if ($rule === 'required') { continue; }

                $parts = array_pad(explode(':', $rule, 2), 2, NULL);
                $name  = $parts[0];
                $arg   = $parts[1];

                switch ($name) {
                    case 'email':
                        if ( ! filter_var($val, FILTER_VALIDATE_EMAIL)) {
                            throw Api_exception::badRequest("field '{$field}' bukan email yang valid");
                        }
                        break;

                    case 'min':
                        if (mb_strlen((string) $val) < (int) $arg) {
                            throw Api_exception::badRequest("field '{$field}' minimal {$arg} karakter");
                        }
                        break;

                    case 'max':
                        if (mb_strlen((string) $val) > (int) $arg) {
                            throw Api_exception::badRequest("field '{$field}' maksimal {$arg} karakter");
                        }
                        break;

                    case 'len':
                        if (mb_strlen((string) $val) !== (int) $arg) {
                            throw Api_exception::badRequest("field '{$field}' harus tepat {$arg} karakter");
                        }
                        break;

                    case 'digits':
                        if ( ! ctype_digit((string) $val)) {
                            throw Api_exception::badRequest("field '{$field}' hanya boleh berisi angka");
                        }
                        break;

                    case 'int_gt':
                        if ( ! $this->is_intish($val) || (int) $val <= (int) $arg) {
                            throw Api_exception::badRequest("field '{$field}' harus bilangan bulat lebih dari {$arg}");
                        }
                        $val = (int) $val;
                        break;

                    case 'int_between':
                        $b = explode(',', $arg);
                        if ( ! $this->is_intish($val) || (int) $val < (int) $b[0] || (int) $val > (int) $b[1]) {
                            throw Api_exception::badRequest("field '{$field}' harus antara {$b[0]} dan {$b[1]}");
                        }
                        $val = (int) $val;
                        break;

                    case 'num_gt':
                        if ( ! is_numeric($val) || Money::cmp($val, $arg) !== 1) {
                            throw Api_exception::badRequest("field '{$field}' harus lebih dari {$arg}");
                        }
                        $val = Money::norm($val);
                        break;

                    case 'num_gte':
                        if ( ! is_numeric($val) || Money::cmp($val, $arg) === -1) {
                            throw Api_exception::badRequest("field '{$field}' minimal {$arg}");
                        }
                        $val = Money::norm($val);
                        break;

                    case 'in':
                        if ( ! in_array((string) $val, explode(',', $arg), TRUE)) {
                            throw Api_exception::badRequest("field '{$field}' harus salah satu dari: {$arg}");
                        }
                        break;
                }
            }

            $out[$field] = $val;
        }

        return $out;
    }

    /** JSON boleh mengirim 5 maupun "5"; keduanya sah sebagai bilangan bulat. */
    private function is_intish($v) {
        if (is_int($v)) { return TRUE; }
        if (is_float($v)) { return floor($v) == $v; }
        return ctype_digit(ltrim((string) $v, '-'));
    }
}

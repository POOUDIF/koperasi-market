// Signer service — §16.4 Opsi 2 blueprint.
//
// Satu-satunya proses yang boleh memegang OWNER_PRIVATE_KEY. Mendengar hanya
// di 127.0.0.1: TIDAK dirancang untuk diekspos ke internet. Chain_client.php
// di aplikasi CI3 memanggilnya lewat cURL, tidak pernah melihat kunci ini.
//
// TODO sebelum dipakai sungguhan:
//   1. Isi signer-service/.env (salin dari .env.example) dengan RPC node,
//      private key wallet minter, dan alamat kontrak CoopGold yang sudah
//      di-deploy.
//   2. Ganti GOLD_ABI di bawah dengan ABI asli hasil compile kontrak
//      CoopGold — placeholder ini hanya menyediakan bentuk fungsi/event
//      yang dirujuk blueprint (§16.1) dan BELUM diverifikasi terhadap
//      kontrak nyata.
//   3. `npm install && npm start`, lalu jalankan di bawah process manager
//      (PM2/NSSM/systemd) terpisah dari worker PHP.

require('dotenv').config();
const express = require('express');
const { ethers } = require('ethers');

const PORT = process.env.PORT || 3100;
const RPC_URL = process.env.POLYGON_RPC_URL || '';
const PRIVATE_KEY = process.env.OWNER_PRIVATE_KEY || '';
const CONTRACT_ADDRESS = process.env.GOLD_CONTRACT_ADDRESS || '';

// TODO: ganti dengan ABI asli kontrak CoopGold yang sudah di-deploy.
const GOLD_ABI = [
  'function mint(address to, uint256 units, uint256 goldTxId) returns (bool)',
  'event GoldMinted(address indexed to, uint256 units, uint256 indexed goldTxId)',
];

const app = express();
app.use(express.json());

let provider = null;
let wallet = null;
let contract = null;

function isConfigured() {
  return RPC_URL !== '' && PRIVATE_KEY !== '' && CONTRACT_ADDRESS !== '';
}

if (isConfigured()) {
  provider = new ethers.JsonRpcProvider(RPC_URL);
  wallet = new ethers.Wallet(PRIVATE_KEY, provider);
  contract = new ethers.Contract(CONTRACT_ADDRESS, GOLD_ABI, wallet);
} else {
  console.warn(
    '[signer] POLYGON_RPC_URL / OWNER_PRIVATE_KEY / GOLD_CONTRACT_ADDRESS belum diisi — ' +
      'service berjalan tapi setiap request akan gagal dengan 503. Isi signer-service/.env untuk mengaktifkan.'
  );
}

function requireConfigured(req, res, next) {
  if (!isConfigured()) {
    return res.status(503).json({ error: 'signer belum dikonfigurasi (.env kosong)' });
  }
  next();
}

// POST /mint { to, units, goldTxId } → { txHash }
app.post('/mint', requireConfigured, async (req, res) => {
  const { to, units, goldTxId } = req.body || {};

  if (!to || !ethers.isAddress(to)) {
    return res.status(400).json({ error: 'field "to" harus alamat wallet yang valid' });
  }
  if (units === undefined || units === null || Number.isNaN(Number(units))) {
    return res.status(400).json({ error: 'field "units" wajib diisi (string angka, 4 desimal on-chain)' });
  }
  if (!Number.isInteger(goldTxId)) {
    return res.status(400).json({ error: 'field "goldTxId" wajib berupa integer' });
  }

  try {
    const tx = await contract.mint(to, BigInt(units), BigInt(goldTxId));
    console.log(`[signer] mint terkirim goldTxId=${goldTxId} to=${to} units=${units} tx=${tx.hash}`);
    return res.json({ txHash: tx.hash });
  } catch (err) {
    console.error(`[signer] mint GAGAL goldTxId=${goldTxId}:`, err.message || err);
    return res.status(502).json({ error: `broadcast gagal: ${err.message || err}` });
  }
});

// GET /receipt?hash=0x... → { status: 1|0 } | 204 (belum ter-mine)
app.get('/receipt', requireConfigured, async (req, res) => {
  const hash = req.query.hash;
  if (!hash || typeof hash !== 'string') {
    return res.status(400).json({ error: 'query "hash" wajib diisi' });
  }

  try {
    const receipt = await provider.getTransactionReceipt(hash);
    if (!receipt) {
      return res.status(204).end(); // belum ter-mine
    }
    return res.json({ status: receipt.status });
  } catch (err) {
    console.error(`[signer] gagal ambil receipt hash=${hash}:`, err.message || err);
    return res.status(502).json({ error: `RPC error: ${err.message || err}` });
  }
});

app.get('/health', (_req, res) => {
  res.json({ status: 'ok', configured: isConfigured() });
});

app.listen(PORT, '127.0.0.1', () => {
  console.log(`[signer] mendengar di 127.0.0.1:${PORT} (configured=${isConfigured()})`);
});

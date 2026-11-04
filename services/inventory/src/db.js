'use strict';

const mysql = require('mysql2/promise');

const requiredEnv = [
  'INVENTORY_DB_HOST',
  'INVENTORY_DB_PORT',
  'INVENTORY_DB_NAME',
  'INVENTORY_DB_USER',
  'INVENTORY_DB_PASS',
];
const missing = requiredEnv.filter((key) => !process.env[key]);

if (missing.length) {
  console.error(JSON.stringify({
    timestamp: new Date().toISOString(),
    level: 'error',
    message: 'missing required environment variables',
    missing,
  }));
  process.exit(1);
}

const pool = mysql.createPool({
  host: process.env.INVENTORY_DB_HOST,
  port: Number(process.env.INVENTORY_DB_PORT),
  database: process.env.INVENTORY_DB_NAME,
  user: process.env.INVENTORY_DB_USER,
  password: process.env.INVENTORY_DB_PASS,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
});

async function ensureSchema() {
  const conn = await pool.getConnection();
  try {
    await conn.query(`
      CREATE TABLE IF NOT EXISTS stock (
        product_id BIGINT PRIMARY KEY,
        quantity   INT NOT NULL DEFAULT 0 CHECK (quantity >= 0),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      )
    `);

    await conn.query(`
      CREATE TABLE IF NOT EXISTS reservations (
        reference  VARCHAR(36) COLLATE ascii_bin PRIMARY KEY,
        status     ENUM('reserved', 'released') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await conn.query(`
      CREATE TABLE IF NOT EXISTS reservation_items (
        reference  VARCHAR(36) COLLATE ascii_bin NOT NULL,
        product_id BIGINT      NOT NULL,
        quantity   INT         NOT NULL,
        PRIMARY KEY (reference, product_id),
        FOREIGN KEY (reference) REFERENCES reservations(reference)
      )
    `);
  } finally {
    conn.release();
  }
}

module.exports = { pool, ensureSchema };
'use strict';

const mysql = require('mysql2/promise');
const logger = require('./logger');

const pool = mysql.createPool({
  host: process.env.INVENTORY_DB_HOST || 'inventory-db',
  port: Number(process.env.INVENTORY_DB_PORT || 3306),
  database: process.env.INVENTORY_DB_NAME || 'inventory',
  user: process.env.INVENTORY_DB_USER || 'inventory',
  password: process.env.INVENTORY_DB_PASS || 'secret',
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
        reference  VARCHAR(36) PRIMARY KEY,
        status     ENUM('reserved', 'released') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await conn.query(`
      CREATE TABLE IF NOT EXISTS reservation_items (
        reference  VARCHAR(36) NOT NULL,
        product_id BIGINT      NOT NULL,
        quantity   INT         NOT NULL,
        PRIMARY KEY (reference, product_id),
        FOREIGN KEY (reference) REFERENCES reservations(reference)
      )
    `);

    logger.info('schema ready');
  } finally {
    conn.release();
  }
}

module.exports = { pool, ensureSchema };
'use strict';

const grpc = require('@grpc/grpc-js');
const { validateAdjust } = require('../validate');
const { grpcStatusForDbError } = require('../dbErrors');
const { beginTx } = require('../db');

async function adjustStock(pool, call, callback) {
  const { product_id: productId, delta } = call.request;

  const validationError = validateAdjust(productId, delta);
  if (validationError) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: validationError });
    return;
  }

  let conn;
  try {
    conn = await pool.getConnection();
  } catch (err) {
    callback({ code: grpc.status.UNAVAILABLE, message: 'inventory database unavailable' });
    return;
  }

  try {
    await beginTx(conn);

    if (delta > 0) {
      await conn.query(
        'INSERT INTO stock (product_id, quantity) VALUES (?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?',
        [productId, delta, delta]
      );
    } else {
      const [result] = await conn.query(
        'UPDATE stock SET quantity = quantity + ? WHERE product_id = ? AND quantity + ? >= 0',
        [delta, productId, delta]
      );

      if (result.affectedRows === 0) {
        await conn.rollback();
        const [rows] = await pool.query('SELECT quantity FROM stock WHERE product_id = ?', [productId]);
        callback(null, { applied: false, quantity: rows.length ? rows[0].quantity : 0 });
        return;
      }
    }

    const [rows] = await conn.query('SELECT quantity FROM stock WHERE product_id = ?', [productId]);
    await conn.commit();

    callback(null, { applied: true, quantity: rows[0].quantity });
  } catch (err) {
    await conn.rollback().catch(() => {});
    callback(grpcStatusForDbError(err) || { code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = adjustStock;
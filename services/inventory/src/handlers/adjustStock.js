'use strict';

const grpc = require('@grpc/grpc-js');

async function adjustStock(pool, call, callback, logger, requestId) {
  const { product_id, delta } = call.request;

  if (!delta) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: 'delta must not be zero' });
    return;
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    // أنشئ سجلاً بكمية صفر إن لم يوجد، حتى يعمل التحديث الشرطي التالي
    await conn.query('INSERT IGNORE INTO stock (product_id, quantity) VALUES (?, 0)', [product_id]);

    const [result] = await conn.query(
      'UPDATE stock SET quantity = quantity + ? WHERE product_id = ? AND quantity + ? >= 0',
      [delta, product_id, delta]
    );

    if (result.affectedRows === 0) {
      await conn.rollback();
      const [rows] = await pool.query('SELECT quantity FROM stock WHERE product_id = ?', [product_id]);
      const quantity = rows.length ? rows[0].quantity : 0;
      logger.info('AdjustStock rejected', { requestId, product_id, delta });
      callback(null, { applied: false, quantity });
      return;
    }

    const [rows] = await conn.query('SELECT quantity FROM stock WHERE product_id = ?', [product_id]);
    await conn.commit();

    logger.info('AdjustStock succeeded', { requestId, product_id, delta });
    callback(null, { applied: true, quantity: rows[0].quantity });
  } catch (err) {
    await conn.rollback().catch(() => {});
    logger.error('AdjustStock failed', { requestId, product_id, error: err.message });
    callback({ code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = adjustStock;
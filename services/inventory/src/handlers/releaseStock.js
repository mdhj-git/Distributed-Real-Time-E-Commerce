'use strict';

const grpc = require('@grpc/grpc-js');

async function releaseStock(pool, call, callback, logger, requestId) {
  const { reference } = call.request;

  if (!reference) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: 'reference is required' });
    return;
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [rows] = await conn.query(
      'SELECT status FROM reservations WHERE reference = ? FOR UPDATE',
      [reference]
    );

    if (!rows.length) {
      // Tombstone: معرّف لم يُحجز من قبل — يمنعه من الحجز لاحقاً إن وصل متأخراً
      try {
        await conn.query(
          "INSERT INTO reservations (reference, status) VALUES (?, 'released')",
          [reference]
        );
      } catch (err) {
        if (err.code !== 'ER_DUP_ENTRY') throw err;
      }
      await conn.commit();
      logger.info('ReleaseStock tombstoned', { requestId, reference });
      callback(null, { released: false });
      return;
    }

    if (rows[0].status === 'released') {
      // متكافئة التكرار: لا شيء للإرجاع
      await conn.commit();
      logger.info('ReleaseStock idempotent replay', { requestId, reference });
      callback(null, { released: false });
      return;
    }

    const [items] = await conn.query(
      'SELECT product_id, quantity FROM reservation_items WHERE reference = ?',
      [reference]
    );

    for (const item of items) {
      await conn.query(
        'UPDATE stock SET quantity = quantity + ? WHERE product_id = ?',
        [item.quantity, item.product_id]
      );
    }

    await conn.query(
      "UPDATE reservations SET status = 'released' WHERE reference = ?",
      [reference]
    );

    await conn.commit();
    logger.info('ReleaseStock succeeded', { requestId, reference, items: items.length });
    callback(null, { released: true });
  } catch (err) {
    await conn.rollback().catch(() => {});
    logger.error('ReleaseStock failed', { requestId, reference, error: err.message });
    callback({ code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = releaseStock;
'use strict';

const grpc = require('@grpc/grpc-js');

async function reserveStock(pool, call, callback, logger, requestId) {
  const { reference, items } = call.request;

  if (!reference || !items || !items.length) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: 'reference and items are required' });
    return;
  }

  for (const item of items) {
    if (!item.quantity || item.quantity <= 0) {
      callback({ code: grpc.status.INVALID_ARGUMENT, message: 'quantity must be positive' });
      return;
    }
  }

  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    // 1) تكرار النداء الآمن: الحجز موجود مسبقاً بهذا المعرّف
    const [existingRows] = await conn.query(
      'SELECT status FROM reservations WHERE reference = ? FOR UPDATE',
      [reference]
    );

    if (existingRows.length) {
      await conn.commit();
      const reserved = existingRows[0].status === 'reserved';
      logger.info('ReserveStock idempotent replay', { requestId, reference, reserved });
      callback(null, { reserved, shortages: [] });
      return;
    }

    // 2) حجز السجل فوراً (يمنع نداء متزامن بنفس المعرّف من الدخول)
    try {
      await conn.query(
        "INSERT INTO reservations (reference, status) VALUES (?, 'reserved')",
        [reference]
      );
    } catch (err) {
      if (err.code === 'ER_DUP_ENTRY') {
        // سباق نادر: نداء آخر بنفس المعرّف سبقنا بين القراءة والإدراج
        await conn.rollback();
        const [rows2] = await pool.query(
          'SELECT status FROM reservations WHERE reference = ?',
          [reference]
        );
        const reserved = rows2.length && rows2[0].status === 'reserved';
        callback(null, { reserved, shortages: [] });
        return;
      }
      throw err;
    }

    // 3) ترتيب العناصر تصاعدياً حسب product_id — يمنع الجمود
    const sorted = [...items].sort((a, b) => Number(a.product_id) - Number(b.product_id));

    const shortages = [];

    for (const item of sorted) {
      const [result] = await conn.query(
        'UPDATE stock SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?',
        [item.quantity, item.product_id, item.quantity]
      );

      if (result.affectedRows === 0) {
        const [availRows] = await conn.query(
          'SELECT quantity FROM stock WHERE product_id = ?',
          [item.product_id]
        );
        const available = availRows.length ? availRows[0].quantity : 0;

        shortages.push({
          product_id: item.product_id,
          requested: item.quantity,
          available,
        });
      }
    }

    if (shortages.length) {
      // تراجع كامل — لا حجز جزئي
      await conn.rollback();
      logger.info('ReserveStock rejected', { requestId, reference, shortages });
      callback(null, { reserved: false, shortages });
      return;
    }

    // 4) حفظ تفاصيل الحجز للإرجاع لاحقاً
    for (const item of sorted) {
      await conn.query(
        'INSERT INTO reservation_items (reference, product_id, quantity) VALUES (?, ?, ?)',
        [reference, item.product_id, item.quantity]
      );
    }

    await conn.commit();
    logger.info('ReserveStock succeeded', { requestId, reference, items: sorted.length });
    callback(null, { reserved: true, shortages: [] });
  } catch (err) {
    await conn.rollback().catch(() => {});
    logger.error('ReserveStock failed', { requestId, reference, error: err.message });
    callback({ code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = reserveStock;
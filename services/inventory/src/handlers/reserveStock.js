'use strict';

const grpc = require('@grpc/grpc-js');
const { validateReference, validateItems } = require('../validate');
const { grpcStatusForDbError } = require('../dbErrors');

async function reserveStock(pool, call, callback) {
  const { reference, items } = call.request;

  const referenceError = validateReference(reference);
  if (referenceError) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: referenceError });
    return;
  }

  const itemsError = validateItems(items);
  if (itemsError) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: itemsError });
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
    await conn.beginTransaction();

    // أدرج أولاً (لا تقرأ ثم تُدرج) — يمنع Deadlock بين نداءين متزامنين بنفس المعرّف
    let isNewReservation = true;
    try {
      await conn.query(
        "INSERT INTO reservations (reference, status) VALUES (?, 'reserved')",
        [reference]
      );
    } catch (err) {
      if (err.code !== 'ER_DUP_ENTRY') throw err;
      isNewReservation = false;
    }

    if (!isNewReservation) {
      const [rows] = await conn.query(
        'SELECT status FROM reservations WHERE reference = ? FOR UPDATE',
        [reference]
      );
      await conn.commit();

      const status = rows.length ? rows[0].status : null;

      if (status === 'released') {
        callback({ code: grpc.status.FAILED_PRECONDITION, message: 'reference already released' });
        return;
      }

      // status === 'reserved' — تكرار نداء آمن، لا نقارن العناصر بالمحاولة الأصلية
      callback(null, { reserved: true, shortages: [] });
      return;
    }

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
        shortages.push({
          product_id: item.product_id,
          requested: item.quantity,
          available: availRows.length ? availRows[0].quantity : 0,
        });
      }
    }

    if (shortages.length) {
      await conn.rollback();
      callback(null, { reserved: false, shortages });
      return;
    }

    for (const item of sorted) {
      await conn.query(
        'INSERT INTO reservation_items (reference, product_id, quantity) VALUES (?, ?, ?)',
        [reference, item.product_id, item.quantity]
      );
    }

    await conn.commit();
    callback(null, { reserved: true, shortages: [] });
  } catch (err) {
    await conn.rollback().catch(() => {});
    callback(grpcStatusForDbError(err) || { code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = reserveStock;
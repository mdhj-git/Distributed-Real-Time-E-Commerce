'use strict';

const grpc = require('@grpc/grpc-js');
const { validateReference, validateItems } = require('../validate');
const { grpcStatusForDbError } = require('../dbErrors');
const { beginTx } = require('../db');

async function attemptReserve(pool, reference, items) {
  let conn;
  try {
    conn = await pool.getConnection();
  } catch (err) {
    return { error: { code: grpc.status.UNAVAILABLE, message: 'inventory database unavailable' } };
  }

  try {
    await beginTx(conn);

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
      // أنهِ المعاملة فوراً لتحرير القفل — لا ترقية قفل، لا SELECT ... FOR UPDATE
      await conn.rollback();
      conn.release();
      conn = null;

      const [rows] = await pool.query('SELECT status FROM reservations WHERE reference = ?', [reference]);

      if (!rows.length) {
        // الصف غاب (المحاولة الأولى تراجعت بسبب نقص) — إشارة لإعادة المحاولة
        return { retry: true };
      }

      if (rows[0].status === 'released') {
        return { error: { code: grpc.status.FAILED_PRECONDITION, message: 'reference already released' } };
      }

      return { response: { reserved: true, shortages: [] } };
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
      return { response: { reserved: false, shortages } };
    }

    for (const item of sorted) {
      await conn.query(
        'INSERT INTO reservation_items (reference, product_id, quantity) VALUES (?, ?, ?)',
        [reference, item.product_id, item.quantity]
      );
    }

    await conn.commit();
    return { response: { reserved: true, shortages: [] } };
  } catch (err) {
    if (conn) await conn.rollback().catch(() => {});
    return { error: grpcStatusForDbError(err) || { code: grpc.status.INTERNAL, message: 'internal error' } };
  } finally {
    if (conn) conn.release();
  }
}

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

  let result = await attemptReserve(pool, reference, items);

  if (result.retry) {
    result = await attemptReserve(pool, reference, items);
    if (result.retry) {
      callback({ code: grpc.status.INTERNAL, message: 'reservation state inconsistent after retry' });
      return;
    }
  }

  if (result.error) {
    callback(result.error);
    return;
  }

  callback(null, result.response);
}

module.exports = reserveStock;
'use strict';

const grpc = require('@grpc/grpc-js');
const { validateReference } = require('../validate');
const { grpcStatusForDbError } = require('../dbErrors');

async function releaseStock(pool, call, callback) {
  const { reference } = call.request;

  const referenceError = validateReference(reference);
  if (referenceError) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: referenceError });
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

    // أدرج Tombstone أولاً تفاؤلياً — لا SELECT FOR UPDATE على صف قد لا يكون موجوداً
    let isNewTombstone = true;
    try {
      await conn.query(
        "INSERT INTO reservations (reference, status) VALUES (?, 'released')",
        [reference]
      );
    } catch (err) {
      if (err.code !== 'ER_DUP_ENTRY') throw err;
      isNewTombstone = false;
    }

    if (isNewTombstone) {
      // لم يكن هناك حجز سابق بهذا المعرّف
      await conn.commit();
      callback(null, { released: false });
      return;
    }

    const [rows] = await conn.query(
      'SELECT status FROM reservations WHERE reference = ? FOR UPDATE',
      [reference]
    );

    if (!rows.length || rows[0].status === 'released') {
      await conn.commit();
      callback(null, { released: false });
      return;
    }

    const [items] = await conn.query(
      'SELECT product_id, quantity FROM reservation_items WHERE reference = ? ORDER BY product_id',
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
    callback(null, { released: true });
  } catch (err) {
    await conn.rollback().catch(() => {});
    callback(grpcStatusForDbError(err) || { code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = releaseStock;
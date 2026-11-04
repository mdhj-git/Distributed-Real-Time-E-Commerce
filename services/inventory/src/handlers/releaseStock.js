'use strict';

const grpc = require('@grpc/grpc-js');
const { validateReference } = require('../validate');
const { grpcStatusForDbError } = require('../dbErrors');
const { beginTx } = require('../db');

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
    await beginTx(conn);

    // UPDATE شرطي — لا SELECT ... FOR UPDATE إطلاقاً
    const [result] = await conn.query(
      "UPDATE reservations SET status = 'released' WHERE reference = ? AND status = 'reserved'",
      [reference]
    );

    if (result.affectedRows === 1) {
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

      await conn.commit();
      callback(null, { released: true });
      return;
    }

    // affectedRows === 0: إما مرجع غير موجود، وإما مرجع مُحرَّر مسبقاً — كلاهما يعيد released:false
    try {
      await conn.query(
        "INSERT IGNORE INTO reservations (reference, status) VALUES (?, 'released')",
        [reference]
      );
    } catch (err) {
      // استثناء احترازي — INSERT IGNORE لا يرمي ER_DUP_ENTRY عادة، لكن نتعامل معه بأمان
      if (err.code !== 'ER_DUP_ENTRY') throw err;
    }

    await conn.commit();
    callback(null, { released: false });
  } catch (err) {
    await conn.rollback().catch(() => {});
    callback(grpcStatusForDbError(err) || { code: grpc.status.INTERNAL, message: 'internal error' });
  } finally {
    conn.release();
  }
}

module.exports = releaseStock;
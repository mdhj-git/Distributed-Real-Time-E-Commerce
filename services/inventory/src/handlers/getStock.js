'use strict';

const grpc = require('@grpc/grpc-js');
const { validateProductIds } = require('../validate');
const { grpcStatusForDbError } = require('../dbErrors');

async function getStock(pool, call, callback) {
  const productIds = (call.request.product_ids || []).map(Number);

  if (!productIds.length) {
    callback(null, { items: [] });
    return;
  }

  const validationError = validateProductIds(productIds);
  if (validationError) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: validationError });
    return;
  }

  try {
    const placeholders = productIds.map(() => '?').join(',');
    const [rows] = await pool.query(
      `SELECT product_id, quantity FROM stock WHERE product_id IN (${placeholders})`,
      productIds
    );

    const found = new Map(rows.map((r) => [String(r.product_id), r.quantity]));

    const items = productIds.map((id) => ({
      product_id: id,
      quantity: found.has(String(id)) ? found.get(String(id)) : 0,
    }));

    callback(null, { items });
  } catch (err) {
    callback(grpcStatusForDbError(err) || { code: grpc.status.INTERNAL, message: 'internal error' });
  }
}

module.exports = getStock;
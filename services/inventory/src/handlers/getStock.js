'use strict';

const grpc = require('@grpc/grpc-js');

async function getStock(pool, call, callback, logger, requestId) {
  const productIds = call.request.product_ids || [];

  if (!productIds.length) {
    callback({ code: grpc.status.INVALID_ARGUMENT, message: 'product_ids must not be empty' });
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

    logger.info('GetStock', { requestId, count: productIds.length });
    callback(null, { items });
  } catch (err) {
    logger.error('GetStock failed', { requestId, error: err.message });
    callback({ code: grpc.status.INTERNAL, message: 'internal error' });
  }
}

module.exports = getStock;
'use strict';

const INT32_MAX = 2147483647;
const UUID_RE = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;
const MAX_ITEMS = 1000;

function isPositiveSafeInt(value) {
  return Number.isSafeInteger(value) && value > 0;
}

function isValidUuid(value) {
  return typeof value === 'string' && value.length === 36 && UUID_RE.test(value);
}

function validateReference(reference) {
  if (!isValidUuid(reference)) {
    return 'reference must be a valid UUID';
  }
  return null;
}

function validateItems(items) {
  if (!Array.isArray(items) || items.length === 0) {
    return 'items must be a non-empty array';
  }
  if (items.length > MAX_ITEMS) {
    return `items must not exceed ${MAX_ITEMS} entries`;
  }

  const seen = new Set();
  for (const item of items) {
    const productId = Number(item.product_id);
    const quantity = Number(item.quantity);

    if (!isPositiveSafeInt(productId)) {
      return 'product_id must be a positive integer';
    }
    if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > INT32_MAX) {
      return `quantity must be an integer between 1 and ${INT32_MAX}`;
    }
    if (seen.has(productId)) {
      return `duplicate product_id ${productId} in items`;
    }
    seen.add(productId);
  }

  return null;
}

function validateProductIds(productIds) {
  if (!Array.isArray(productIds)) {
    return 'product_ids must be an array';
  }
  if (productIds.length > MAX_ITEMS) {
    return `product_ids must not exceed ${MAX_ITEMS} entries`;
  }
  for (const id of productIds) {
    if (!isPositiveSafeInt(Number(id))) {
      return 'product_ids must contain positive integers';
    }
  }
  return null;
}

function validateAdjust(productId, delta) {
  if (!isPositiveSafeInt(Number(productId))) {
    return 'product_id must be a positive integer';
  }
  const d = Number(delta);
  if (!Number.isSafeInteger(d) || d === 0 || d > INT32_MAX || d < -INT32_MAX) {
    return `delta must be a non-zero integer within +/-${INT32_MAX}`;
  }
  return null;
}

module.exports = {
  INT32_MAX,
  MAX_ITEMS,
  isPositiveSafeInt,
  isValidUuid,
  validateReference,
  validateItems,
  validateProductIds,
  validateAdjust,
};
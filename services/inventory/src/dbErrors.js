'use strict';

const grpc = require('@grpc/grpc-js');

const UNAVAILABLE_ERROR_CODES = new Set([
  'ER_LOCK_DEADLOCK',       // 1213
  'ER_LOCK_WAIT_TIMEOUT',   // 1205
  'PROTOCOL_CONNECTION_LOST',
  'ECONNREFUSED',
  'ETIMEDOUT',
  'ENOTFOUND',
  'PROTOCOL_SEQUENCE_TIMEOUT',
]);

const RANGE_ERROR_CODES = new Set([
  'ER_WARN_DATA_OUT_OF_RANGE', // 1264
  'ER_DATA_TOO_LONG',
]);

function grpcStatusForDbError(err) {
  if (UNAVAILABLE_ERROR_CODES.has(err.code)) {
    return { code: grpc.status.UNAVAILABLE, message: 'inventory database unavailable' };
  }
  if (RANGE_ERROR_CODES.has(err.code)) {
    return { code: grpc.status.INVALID_ARGUMENT, message: 'value out of range' };
  }
  return null;
}

module.exports = { grpcStatusForDbError };
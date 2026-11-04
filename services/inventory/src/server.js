'use strict';

const path = require('path');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');

const logger = require('./logger');
const { pool, ensureSchema } = require('./db');

const getStock = require('./handlers/getStock');
const reserveStock = require('./handlers/reserveStock');
const releaseStock = require('./handlers/releaseStock');
const adjustStock = require('./handlers/adjustStock');

const PROTO_PATH = path.join(__dirname, '..', 'proto', 'inventory', 'v1', 'inventory.proto');

const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
  keepCase: true,
  longs: Number,
  enums: String,
  defaults: true,
  oneofs: true,
});

const inventoryProto = grpc.loadPackageDefinition(packageDefinition).inventory.v1;

function requestIdFrom(call) {
  const meta = call.metadata.get('x-request-id');
  return meta.length ? meta[0] : null;
}

function wrap(name, handler) {
  return (call, callback) => {
    const requestId = requestIdFrom(call);
    const startedAt = process.hrtime.bigint();

    const done = (err, response) => {
      const durationMs = Number(process.hrtime.bigint() - startedAt) / 1e6;
      const status = err ? (err.code !== undefined ? err.code : grpc.status.INTERNAL) : grpc.status.OK;

      logger.info(name, {
        request_id: requestId,
        status,
        duration_ms: Math.round(durationMs * 100) / 100,
      });

      callback(err, response);
    };

    Promise.resolve()
      .then(() => handler(pool, call, done))
      .catch((err) => {
        logger.error(`${name} unhandled error`, { request_id: requestId, error: err.message });
        done({ code: grpc.status.UNAVAILABLE, message: 'internal failure' });
      });
  };
}

async function waitForDatabase(retries = 20, delayMs = 2000) {
  for (let attempt = 1; attempt <= retries; attempt++) {
    try {
      await ensureSchema();
      logger.info('schema ready');
      return;
    } catch (err) {
      logger.warn('database not ready, retrying', { attempt, error: err.message });
      await new Promise((resolve) => setTimeout(resolve, delayMs));
    }
  }
  throw new Error('database never became ready');
}

async function main() {
  await waitForDatabase();

  const server = new grpc.Server();

  server.addService(inventoryProto.Inventory.service, {
    GetStock: wrap('GetStock', getStock),
    ReserveStock: wrap('ReserveStock', reserveStock),
    ReleaseStock: wrap('ReleaseStock', releaseStock),
    AdjustStock: wrap('AdjustStock', adjustStock),
  });

  const port = process.env.INVENTORY_GRPC_PORT || '50051';
  const bindAddress = `0.0.0.0:${port}`;

  server.bindAsync(bindAddress, grpc.ServerCredentials.createInsecure(), (err, boundPort) => {
    if (err) {
      logger.error('failed to bind', { error: err.message });
      process.exit(1);
    }
    logger.info('inventory service listening', { port: boundPort });
  });

  const shutdown = () => {
    logger.info('shutting down');
    server.tryShutdown((err) => {
      if (err) {
        logger.error('graceful shutdown failed, forcing', { error: err.message });
        server.forceShutdown();
      }
      pool.end().finally(() => process.exit(0));
    });
  };

  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
  process.on('unhandledRejection', (reason) => {
    logger.error('unhandled rejection', {
      error: reason instanceof Error ? reason.message : String(reason),
    });
  });
}

main().catch((err) => {
  logger.error('fatal startup error', { error: err.message });
  process.exit(1);
});
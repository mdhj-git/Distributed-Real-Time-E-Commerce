FROM node:22.11-alpine

WORKDIR /app

COPY --chown=node:node services/inventory/package.json services/inventory/package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts

COPY --chown=node:node services/inventory/src ./src
COPY --chown=node:node proto ./proto

USER node

EXPOSE 50051

CMD ["node", "src/server.js"]
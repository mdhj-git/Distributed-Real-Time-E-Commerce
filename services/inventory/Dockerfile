FROM node:20-alpine

WORKDIR /app

COPY services/inventory/package.json ./
RUN npm install --omit=dev

COPY services/inventory/src ./src
COPY proto ./proto

EXPOSE 50051

CMD ["node", "src/server.js"]
#!/bin/sh
set -e

PROTO="proto/inventory/v1/inventory.proto"
ADDR="inventory:50051"
SERVICE="inventory.v1.Inventory"

echo "=== Test A: Reserve N=5 stock, 15 concurrent reserves with different references ==="
grpcurl -plaintext -proto "$PROTO" -d '{"product_id": 100, "delta": 5}' "$ADDR" "$SERVICE/AdjustStock"

for i in $(seq 1 15); do
  REF=$(printf 'aaaaaaaa-aaaa-aaaa-aaaa-%012d' "$i")
  grpcurl -plaintext -proto "$PROTO" -d "{\"reference\": \"$REF\", \"items\": [{\"product_id\": 100, \"quantity\": 1}]}" "$ADDR" "$SERVICE/ReserveStock" > "/tmp/out_$i.json" 2>&1 &
done
wait

SUCCESS=$(grep -l '"reserved": true' /tmp/out_*.json | wc -l)
echo "Successful reserves: $SUCCESS (expected: 5)"
grpcurl -plaintext -proto "$PROTO" -d '{"product_ids": [100]}' "$ADDR" "$SERVICE/GetStock"
rm -f /tmp/out_*.json

echo ""
echo "=== Test B: 10 concurrent reserves with SAME reference ==="
SAME_REF="bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"
grpcurl -plaintext -proto "$PROTO" -d '{"product_id": 200, "delta": 10}' "$ADDR" "$SERVICE/AdjustStock"

for i in $(seq 1 10); do
  grpcurl -plaintext -proto "$PROTO" -d "{\"reference\": \"$SAME_REF\", \"items\": [{\"product_id\": 200, \"quantity\": 3}]}" "$ADDR" "$SERVICE/ReserveStock" > "/tmp/sameref_$i.json" 2>&1 &
done
wait

cat /tmp/sameref_*.json
echo "---"
grpcurl -plaintext -proto "$PROTO" -d '{"product_ids": [200]}' "$ADDR" "$SERVICE/GetStock"
echo "(expected quantity: 7 — exactly one deduction of 3 from 10)"
rm -f /tmp/sameref_*.json

echo ""
echo "=== Test C: 10 concurrent releases with SAME reference ==="
for i in $(seq 1 10); do
  grpcurl -plaintext -proto "$PROTO" -d "{\"reference\": \"$SAME_REF\"}" "$ADDR" "$SERVICE/ReleaseStock" > "/tmp/rel_$i.json" 2>&1 &
done
wait

cat /tmp/rel_*.json
echo "---"
grpcurl -plaintext -proto "$PROTO" -d '{"product_ids": [200]}' "$ADDR" "$SERVICE/GetStock"
echo "(expected quantity: 10 — restored exactly once)"
rm -f /tmp/rel_*.json

echo ""
echo "=== Test D: Adjust (+) and Reserve concurrent on same product ==="
grpcurl -plaintext -proto "$PROTO" -d '{"product_id": 300, "delta": 5}' "$ADDR" "$SERVICE/AdjustStock"

for i in $(seq 1 5); do
  REF=$(printf 'cccccccc-cccc-cccc-cccc-%012d' "$i")
  grpcurl -plaintext -proto "$PROTO" -d "{\"reference\": \"$REF\", \"items\": [{\"product_id\": 300, \"quantity\": 1}]}" "$ADDR" "$SERVICE/ReserveStock" &
  grpcurl -plaintext -proto "$PROTO" -d '{"product_id": 300, "delta": 2}' "$ADDR" "$SERVICE/AdjustStock" &
done
wait
echo "(check logs for any Deadlock line above)"

echo ""
echo "=== Test E: Release non-existent reference, then Reserve with same reference ==="
GHOST_REF="dddddddd-dddd-dddd-dddd-dddddddddddd"
grpcurl -plaintext -proto "$PROTO" -d "{\"reference\": \"$GHOST_REF\"}" "$ADDR" "$SERVICE/ReleaseStock"
grpcurl -plaintext -proto "$PROTO" -d "{\"reference\": \"$GHOST_REF\", \"items\": [{\"product_id\": 100, \"quantity\": 1}]}" "$ADDR" "$SERVICE/ReserveStock" || true
echo "(expected: FAILED_PRECONDITION above)"

echo ""
echo "=== DONE ==="
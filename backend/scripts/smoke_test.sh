#!/usr/bin/env bash
# OptiFleet R4 post-deployment smoke test.
#
# Read-only / additive-only sanity checks intended to run immediately after
# a deployment, against the environment's own BASE_URL. Exits non-zero on
# the first unexpected status code. Requires a tenant user's credentials
# (SMOKE_EMAIL / SMOKE_PASSWORD) already provisioned in that environment —
# this script never creates or deletes data itself.
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000/api/v1}"
SMOKE_EMAIL="${SMOKE_EMAIL:?set SMOKE_EMAIL}"
SMOKE_PASSWORD="${SMOKE_PASSWORD:?set SMOKE_PASSWORD}"

pass=0
fail=0

check() {
  local desc="$1" method="$2" path="$3" expect="$4" body="${5:-}"
  local args=(-s -o /dev/null -w "%{http_code}" -X "$method" "${BASE_URL}${path}" -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN}")
  if [ -n "$body" ]; then
    args+=(-H "Content-Type: application/json" -d "$body")
  fi
  local code
  code=$(curl "${args[@]}")
  if [ "$code" = "$expect" ]; then
    echo "[PASS] $desc -> $code"
    pass=$((pass+1))
  else
    echo "[FAIL] $desc -> expected $expect, got $code"
    fail=$((fail+1))
  fi
}

echo "=== OptiFleet smoke test against ${BASE_URL} ==="

LOGIN_RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"email\":\"${SMOKE_EMAIL}\",\"password\":\"${SMOKE_PASSWORD}\"}")
TOKEN=$(echo "$LOGIN_RESPONSE" | node -e "let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{try{console.log(JSON.parse(d).data.token)}catch(e){console.log('')}})")
if [ -z "$TOKEN" ]; then
  echo "[FAIL] auth login -> could not obtain token"
  exit 1
fi
echo "[PASS] auth login -> token acquired"
pass=$((pass+1))

# Core module list endpoints (existing functionality — regression guard).
check "vehicles list" GET "/app/vehicles" 200
check "work orders list" GET "/app/work-orders" 200
check "products list" GET "/app/products" 200
check "warehouses list" GET "/app/warehouses" 200
check "tires list" GET "/app/tires" 200
check "partners list" GET "/app/partners" 200
check "purchase requests list" GET "/app/purchase-requests" 200
check "purchase orders list" GET "/app/purchase-orders" 200

# R1: Workshop Invoice and Settlement.
check "workshop invoices list" GET "/app/workshop-invoices" 200

# R2: Tire Scoring Configuration (generic Configuration endpoints, TIRE_SCORING type).
check "tire scoring configuration sets" GET "/app/configuration/sets?type=TIRE_SCORING" 200

# Auth boundary: an unauthenticated request must be rejected, never silently allowed.
UNAUTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/app/vehicles" -H "Accept: application/json")
if [ "$UNAUTH_CODE" = "401" ]; then
  echo "[PASS] unauthenticated request rejected -> 401"
  pass=$((pass+1))
else
  echo "[FAIL] unauthenticated request -> expected 401, got $UNAUTH_CODE"
  fail=$((fail+1))
fi

echo ""
echo "=== Smoke test result: ${pass} passed, ${fail} failed ==="
if [ "$fail" -gt 0 ]; then
  exit 1
fi

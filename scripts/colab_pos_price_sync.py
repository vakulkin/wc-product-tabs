"""
Google Colab script to sync POS prices into WC Product Tabs.

What it does:
1) Reads the latest uploaded XLSX file in /content that starts with export_products_.
2) Parses POS product ID + price columns (supports first empty row and comma decimals).
3) Pulls current product rows from:
   GET /wp-json/wc-product-tabs/v1/products
4) Matches by pos_id and sends batched updates to:
   POST /wp-json/wc-product-tabs/v1/products/batch-update

Expected auth header for both endpoints:
Authorization: Bearer <token>
"""

from __future__ import annotations

import glob
import math
import os
import re
from dataclasses import dataclass
from typing import Dict, List, Optional, Tuple

import pandas as pd
import requests

try:
    from google.colab import files  # type: ignore
except Exception:  # pragma: no cover - not running in Colab
    files = None


# =========================
# Config
# =========================
BASE_URL = "https://natalias5.sg-host.com"  # e.g. https://shop.example.com
API_TOKEN = "fI3xeW87yo4qtWsG"

UPLOAD_DIR = "/content"
FILE_GLOB = "export_products_*.xlsx"

APPLY_UPDATES = True
REQUIRE_CONFIRMATION = False
REQUEST_TIMEOUT_SECONDS = 30
CHUNK_SIZE = 500  # plugin max is 500 updates per request


@dataclass
class PosPriceRow:
    pos_id: str
    price: float


def normalize_text(value: object) -> str:
    if value is None:
        return ""
    return re.sub(r"\s+", " ", str(value)).strip().lower()


def parse_pos_id(value: object) -> Optional[str]:
    if value is None:
        return None
    raw = str(value).strip()
    if not raw or raw.lower() in {"nan", "none"}:
        return None

    if re.fullmatch(r"\d+\.0+", raw):
        raw = raw.split(".", 1)[0]

    return raw


def parse_price(value: object) -> Optional[float]:
    if value is None:
        return None

    if isinstance(value, (int, float)):
        if isinstance(value, float) and math.isnan(value):
            return None
        return float(value)

    raw = str(value).strip()
    if not raw or raw.lower() in {"nan", "none"}:
        return None

    raw = raw.replace("\u00a0", " ").replace(" ", "")
    raw = raw.replace(",", ".")
    raw = re.sub(r"[^0-9.\-]", "", raw)

    if raw.count(".") > 1:
        parts = raw.split(".")
        raw = "".join(parts[:-1]) + "." + parts[-1]

    try:
        return float(raw)
    except ValueError:
        return None


def find_latest_export_xlsx(upload_dir: str, file_glob: str) -> Optional[str]:
    pattern = f"{upload_dir.rstrip('/')}/{file_glob}"
    candidates = glob.glob(pattern)
    if not candidates:
        return None
    return max(candidates, key=os.path.getmtime)


def maybe_upload_file_if_missing(upload_dir: str) -> None:
    if files is None:
        return

    if find_latest_export_xlsx(upload_dir, FILE_GLOB):
        return

    print("No export_products_*.xlsx found in /content.")
    print("Upload your XLSX export now...")
    files.upload()


def detect_header_row(raw_df: pd.DataFrame) -> int:
    for idx in range(len(raw_df)):
        row_values = [normalize_text(v) for v in raw_df.iloc[idx].tolist()]
        if any("posterid product_id" in v for v in row_values):
            return idx
    raise ValueError("Could not find header row containing 'PosterID product_id'.")


def resolve_column(columns: List[str], required_substring: str) -> str:
    required = normalize_text(required_substring)
    for col in columns:
        if required in normalize_text(col):
            return col
    raise ValueError(f"Missing column containing: {required_substring}")


def load_pos_prices_from_xlsx(path: str) -> Dict[str, float]:
    raw_df = pd.read_excel(path, header=None, engine="openpyxl")
    header_row_idx = detect_header_row(raw_df)

    header_values = [str(v).strip() if pd.notna(v) else "" for v in raw_df.iloc[header_row_idx].tolist()]
    data_df = raw_df.iloc[header_row_idx + 1 :].copy()
    data_df.columns = header_values
    data_df = data_df.dropna(how="all")

    pos_col = resolve_column(data_df.columns.tolist(), "PosterID product_id")
    price_col = resolve_column(data_df.columns.tolist(), "Ціна")

    pos_price: Dict[str, float] = {}

    for _, row in data_df.iterrows():
        pos_id = parse_pos_id(row.get(pos_col))
        price = parse_price(row.get(price_col))

        if not pos_id or price is None:
            continue

        # If POS ID appears multiple times, keep the last one from the file.
        pos_price[pos_id] = price

    if not pos_price:
        raise ValueError("No valid POS ID + price rows found in XLSX.")

    return pos_price


def make_headers(token: str) -> Dict[str, str]:
    return {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
    }


def get_site_product_rows(base_url: str, token: str) -> Dict[str, List[dict]]:
    url = f"{base_url.rstrip('/')}/wp-json/wc-product-tabs/v1/products"
    response = requests.get(url, headers=make_headers(token), timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()

    payload = response.json()
    if not isinstance(payload, dict):
        raise ValueError("Unexpected response from products API: expected object.")

    return payload


def build_pos_index(site_rows: Dict[str, List[dict]]) -> Dict[str, List[dict]]:
    pos_index: Dict[str, List[dict]] = {}

    for product_id, rows in site_rows.items():
        if not isinstance(rows, list):
            continue

        for row in rows:
            if not isinstance(row, dict):
                continue

            pos_id = parse_pos_id(row.get("pos_id"))
            if not pos_id:
                continue

            item = {
                "product_id": int(row.get("product_id") or product_id),
                "field_key": str(row.get("field_key") or "").strip(),
                "current_price": row.get("price"),
                "pos_id": pos_id,
            }
            pos_index.setdefault(pos_id, []).append(item)

    return pos_index


def build_updates(pos_prices: Dict[str, float], pos_index: Dict[str, List[dict]]) -> Tuple[List[dict], List[str]]:
    updates: List[dict] = []
    missing_pos_ids: List[str] = []
    regular_price_by_product: Dict[int, float] = {}
    min_custom_price_by_product: Dict[int, float] = {}

    for pos_id, new_price in pos_prices.items():
        matches = pos_index.get(pos_id, [])
        if not matches:
            missing_pos_ids.append(pos_id)
            continue

        for match in matches:
            product_id = int(match["product_id"])
            field_key = str(match["field_key"] or "").strip()

            if not field_key:
                continue

            if field_key == "regular":
                regular_price_by_product.setdefault(product_id, new_price)
                continue

            updates.append(
                {
                    "product_id": product_id,
                    "field_key": field_key,
                    "price": format_price_string(new_price),
                }
            )

            existing_min = min_custom_price_by_product.get(product_id)
            if existing_min is None or new_price < existing_min:
                min_custom_price_by_product[product_id] = new_price

    # For regular price:
    # 1) use explicit regular POS mapping if present,
    # 2) otherwise fallback to minimal matched custom-field price.
    products_for_regular = set(min_custom_price_by_product) | set(regular_price_by_product)
    for product_id in sorted(products_for_regular):
        regular_price = regular_price_by_product.get(product_id)
        if regular_price is None:
            regular_price = min_custom_price_by_product.get(product_id)
        if regular_price is None:
            continue

        updates.append(
            {
                "product_id": product_id,
                "price": format_price_string(regular_price),
            }
        )

    return updates, missing_pos_ids


def chunked(values: List[dict], chunk_size: int) -> List[List[dict]]:
    return [values[i : i + chunk_size] for i in range(0, len(values), chunk_size)]

def format_price_string(price: float) -> str:
    if price == int(price):
        return str(int(price))
    return str(price)


def send_batch_updates(base_url: str, token: str, updates: List[dict]) -> List[dict]:
    url = f"{base_url.rstrip('/')}/wp-json/wc-product-tabs/v1/products/batch-update"
    all_results: List[dict] = []

    chunks = chunked(updates, CHUNK_SIZE)
    for i, chunk in enumerate(chunks, start=1):
        print(f"Sending chunk {i}/{len(chunks)} with {len(chunk)} updates...")
        response = requests.post(
            url,
            headers=make_headers(token),
            json={"updates": chunk},
            timeout=REQUEST_TIMEOUT_SECONDS,
        )

        if response.status_code >= 400:
            raise RuntimeError(
                f"Batch update failed for chunk {i}: HTTP {response.status_code} - {response.text[:500]}"
            )

        data = response.json()
        if not isinstance(data, list):
            raise RuntimeError(f"Unexpected batch response for chunk {i}: {data}")

        all_results.extend(data)

    return all_results


def print_results_summary(results: List[dict]) -> None:
    success_count = sum(1 for r in results if isinstance(r, dict) and r.get("success") is True)
    fail_items = [r for r in results if not (isinstance(r, dict) and r.get("success") is True)]

    print("\n=== Update Results ===")
    print(f"Total results: {len(results)}")
    print(f"Successful: {success_count}")
    print(f"Failed: {len(fail_items)}")

    if fail_items:
        print("\nFirst failed items:")
        for item in fail_items[:20]:
            print(item)


def main() -> None:
    if not BASE_URL.startswith("http"):
        raise ValueError("Set BASE_URL to your site URL, e.g. https://shop.example.com")

    if API_TOKEN == "PASTE_BEARER_TOKEN_HERE":
        raise ValueError("Set API_TOKEN before running.")

    maybe_upload_file_if_missing(UPLOAD_DIR)

    xlsx_path = find_latest_export_xlsx(UPLOAD_DIR, FILE_GLOB)
    if not xlsx_path:
        raise FileNotFoundError("No file found: /content/export_products_*.xlsx")

    print(f"Using XLSX file: {xlsx_path}")

    pos_prices = load_pos_prices_from_xlsx(xlsx_path)
    print(f"Parsed POS prices: {len(pos_prices)}")

    site_rows = get_site_product_rows(BASE_URL, API_TOKEN)
    pos_index = build_pos_index(site_rows)
    print(f"POS IDs available on site: {len(pos_index)}")

    updates, missing_pos_ids = build_updates(pos_prices, pos_index)
    print(f"Prepared updates: {len(updates)}")
    print(f"POS IDs from XLSX not found on site: {len(missing_pos_ids)}")

    if missing_pos_ids:
        print("Missing POS IDs (first 30):")
        print(missing_pos_ids[:30])

    if not updates:
        print("Nothing to update.")
        return

    print("\nPreview (first 10 updates):")
    for item in updates[:10]:
        print(item)

    if not APPLY_UPDATES:
        print("\nDry run mode: no updates sent. Set APPLY_UPDATES = True to send changes.")
        return

    if REQUIRE_CONFIRMATION:
        answer = input("Type YES to send updates: ").strip()
        if answer != "YES":
            print("Canceled by user.")
            return

    results = send_batch_updates(BASE_URL, API_TOKEN, updates)
    print_results_summary(results)


if __name__ == "__main__":
    main()

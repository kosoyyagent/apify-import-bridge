"""bridge.py — Apify dataset -> нормализованная таблица для импорта (WP/любая CMS).

Задача-кейс: у заказчика есть Apify-парсеры (акторы), собирающие товары,
и самописный WP-плагин импорта. Этот мост — прослойка между ними:
тянет выдачу акторов, приводит поля к схеме плагина, чистит дубли.

Вход:
  --dataset URL|ID     id датасета Apify ИЛИ actor~task (см. --token)
                       или локальный json/csv с уже выгруженными items
  --mapping FILE       json-файл соответствия полей:
                       {"поле_назначения": ["вариант1","вариант2", ...]}
                       значения проверяются по очереди, первый непустой wins
  --dedup COL[,COL]    колонки ключа дедупликации (по умолчанию: url или sku)
  --out FILE           результат: csv или xlsx (по расширению)
  --token TOKEN        API-токен Apify (или env APIFY_TOKEN)

Пример:
  python bridge.py --dataset items.json --mapping map.json --out out.xlsx
  python bridge.py --dataset AbCdEfGh --token apify_api_xxx --mapping map.json
"""
import argparse
import hashlib
import json
import os
import re
import sys

import pandas as pd
import requests

APIFY_API = "https://api.apify.com/v2"


def fetch_dataset(dataset_id, token=None, limit=100000):
    """Все items датасета Apify."""
    items, offset = [], 0
    while True:
        r = requests.get(
            f"{APIFY_API}/datasets/{dataset_id}/items",
            params={"token": token, "offset": offset, "limit": 1000,
                    "format": "json", "clean": "true"},
            timeout=60)
        r.raise_for_status()
        chunk = r.json()
        if not chunk:
            break
        items += chunk
        offset += len(chunk)
        if len(chunk) < 1000 or len(items) >= limit:
            break
    return items


def last_dataset_of_actor(actor_id, token):
    """Id последнего успешного датасета актора."""
    r = requests.get(f"{APIFY_API}/acts/{actor_id}/runs/last",
                     params={"token": token, "status": "SUCCEEDED"}, timeout=30)
    r.raise_for_status()
    return r.json()["data"]["defaultDatasetId"]


def load_items(src, token):
    if os.path.isfile(src):
        if src.lower().endswith(".json"):
            return json.load(open(src, encoding="utf-8"))
        return pd.read_csv(src).to_dict("records")
    if "~" in src:  # actor~task form -> last run dataset
        src = last_dataset_of_actor(src, token)
    return fetch_dataset(src, token)


def flatten(d, prefix=""):
    """Плоский словарь: a.b.c -> 'a.b.c'."""
    out = {}
    for k, v in d.items():
        key = f"{prefix}.{k}" if prefix else k
        if isinstance(v, dict):
            out.update(flatten(v, key))
        else:
            out[key] = v
    return out


def apply_mapping(items, mapping):
    """mapping: {target: [source_keys...]}. Возвращает DataFrame."""
    rows = []
    for it in items:
        flat = flatten(it)
        row = {}
        for target, variants in mapping.items():
            if isinstance(variants, str):
                variants = [variants]
            val = ""
            for vk in variants:
                v = flat.get(vk)
                if v not in (None, "", []):
                    val = str(v) if not isinstance(v, list) else ", ".join(map(str, v))
                    break
            row[target] = val
        rows.append(row)
    return pd.DataFrame(rows)


def dedup(df, cols):
    cols = [c for c in cols if c in df.columns]
    if not cols:
        return df
    key = df[cols].fillna("").astype(str).agg("|".join, axis=1)
    key = key.map(lambda s: hashlib.md5(re.sub(r"\s+", "", s.lower()).encode()).hexdigest())
    return df.loc[~key.duplicated()].reset_index(drop=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dataset", required=True)
    ap.add_argument("--mapping", required=True)
    ap.add_argument("--dedup", default="")
    ap.add_argument("--out", default="bridge_out.xlsx")
    ap.add_argument("--token", default=os.environ.get("APIFY_TOKEN", ""))
    a = ap.parse_args()

    items = load_items(a.dataset, a.token)
    print(f"items: {len(items)}", file=sys.stderr)
    if not items:
        sys.exit("пустая выдача")

    mapping = json.load(open(a.mapping, encoding="utf-8"))
    df = apply_mapping(items, mapping)
    before = len(df)
    cols = [c.strip() for c in a.dedup.split(",") if c.strip()] or \
           [c for c in ("url", "sku", "link") if c in df.columns]
    df = dedup(df, cols)
    print(f"dedup by {cols}: {before} -> {len(df)}", file=sys.stderr)

    if a.out.lower().endswith(".csv"):
        df.to_csv(a.out, index=False, encoding="utf-8-sig")
    else:
        df.to_excel(a.out, index=False)
    print("saved:", a.out, file=sys.stderr)


if __name__ == "__main__":
    main()

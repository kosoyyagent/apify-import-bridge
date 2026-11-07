# apify-import-bridge

Прослойка между Apify-парсерами и импортом в WordPress/CMS: тянет
items датасета, приводит поля к схеме плагина, чистит дубли, отдаёт
готовый CSV/XLSX. В комплекте — самописный WP-плагин импорта по cron.

## Части

- **`bridge.py`** — CLI: Apify dataset (id/URL/токен или локальный
  json/csv) → нормализация по map-файлу → дедупликация → csv/xlsx.
- **`wp_plugin/selleye-apify-import.php`** — WP-плагин: импорт датасета
  по расписанию, маппинг полей в админке, дедупликация по meta-ключу,
  статус-лог. `selleye-apify-import.zip` — готовый архив для установки.

## bridge.py

```bash
python bridge.py --dataset items.json --mapping map.json --out out.xlsx
python bridge.py --dataset AbCdEfGh --token apify_api_xxx --mapping map.json
python bridge.py --dataset actor~task --token $APIFY_TOKEN --out out.csv
```

- `--mapping FILE` — json соответствия полей: `{"поле_назначения": ["вариант1","вариант2"]}`,
  первый непустой вариант побеждает (точечный путь `attrs.color` тоже ок).
- `--dedup COL[,COL]` — ключ дедупликации (по умолчанию url или sku).
- `--out` — csv или xlsx по расширению.

## Демо (docs/)

```bash
python bridge.py --dataset docs/_demo_items.json --mapping docs/_demo_map.json --out out.xlsx
# items: 3 → dedup by ['sku']: 3 -> 2 → out.xlsx
```

Реальные артефакты прогона: `docs/_demo_out.xlsx` (нормализованная
таблица), `docs/_demo_dedup.xlsx`, `docs/_demo_analytics.xlsx`.

## Зависимости

```bash
pip install -r requirements.txt   # pandas, requests, openpyxl
```

APIFY_TOKEN можно задать переменной окружения вместо `--token`.

MIT.

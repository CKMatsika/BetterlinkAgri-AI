# BetterlinkAgr AI — Zimbabwe AI Agri-Input Sales Representative

White-label, multi-tenant **AI Agri-Input Sales Representative & Photo Diagnosis Platform** engineered for the **Zimbabwean** agricultural market.

## Core Loop
`Profile -> Engage -> Diagnose -> Recommend -> Qualify -> Connect (+ Follow-Up & Ward Outbreak Learning)`

## Key Features
- **Photo Diagnosis Engine**: Multimodal image quality gate (Laplacian variance sharpness, brightness, resolution, EXIF stripping with CDPA GPS consent gate), first-pass identification (top 3 ranked candidates with ChiShona `sn` & isiNdebele `nd` names), and an adaptive 1-at-a-time clarifying question loop (`AdaptiveQuestionEngine`).
- **Non-Negotiable Safety Guardrails (`SafetyGuardrailValidator`)**:
  - Doses, rates, PHI, REI, and registration claims come strictly from tenant-approved catalogue rows (`product_targets`, `fertiliser_recommendations`) and deterministic calculators (`ApplicationRateCalculator`).
  - Zero hallucinated doses or unapproved tank mixes.
  - Mandatory PPE, bee, waterway, child, and pregnancy warnings on every chemical advisory.
- **3-Way Deterministic Fertiliser Rate Calculator (`ApplicationRateCalculator`)**:
  - Per Plant (grams + local Nr. 5 / Nr. 8 cup or bottle-cap equivalent)
  - Per **Pfumvudza / Intwasa Planting Station** (`39m × 16m = 624 m²`, `1,456 stations`)
  - Per Hectare (`kg/ha` + `50 kg` bag count) scaled to the farmer's field size.
- **Location-Aware Retailer Finder (`OutletFinderService`)**:
  - Deterministic PostGIS / spatial search expanding `25 km -> 50 km -> 100 km -> Province`.
  - Ranks by stock confidence & 48-hour freshness, distance, open now, delivery, and dual-currency price (**USD** & **ZiG**).
  - Enforces privacy: **never shares exact farmer GPS coordinates with outlets before explicit farmer consent** (shares only ward & distance band).
  - 30-minute Outlet Reservation SLA with automatic cascade to the next nearest outlet, then to a Sales Representative.
- **Three Filament v3 Panels**:
  1. **Platform Admin (`/platform`)**: Tenant onboarding & `Manufacturer -> Distributor -> Agro-Dealer` supply links (`tenant_supply_links`).
  2. **Tenant Admin (`/admin/{tenant}`)**: Catalogue & dose matrices, RAG PDF uploader (`pgvector`), Recommendation Boundaries & Escalation Rules editor, Agronomist Review Queue, Conversation Audit viewer, Connected Farmer Profile Panel, Leads, Programmes, Campaigns, Events, and Analytics.
  3. **Outlet Portal (`/outlet`)**: Mobile-first shelf stock band updater, WhatsApp stock line parser, and 30-minute SLA reservation inbox.

## Documentation
- [System Architecture](docs/ARCHITECTURE.md)
- [Entity-Relationship Diagram & 42-Table Schema](docs/ERD.md)
- [Authoritative 24-State Conversation & Diagnosis State Machine](docs/DIAGNOSIS_STATE_MACHINE.md)
- [Project Folder Structure](docs/FOLDER_STRUCTURE.md)
- [OpenAPI 3.1 Specification](docs/openapi.yaml)

## Quick Start
```bash
# Instant Local Preview Server
php -S localhost:8000 -t public public/index.php

# Full Laravel + PostgreSQL (pgvector + PostGIS) + Redis Setup
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
./vendor/bin/pest
```

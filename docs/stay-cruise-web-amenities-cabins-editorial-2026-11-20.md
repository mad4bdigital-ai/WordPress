# Wayfatours: Official-web Cruise Amenities and draft cabin-category sourcing

Executed 20 November 2026, Wayfatours Production. Operator-published facts and official web indexes were reviewed, direct automated requests were made only where robots.txt permitted, and no external photographs were copied. This is source-data enrichment, NOT supplier booking authority.

## Scope, source provenance, and conflicts

- Storia The Dahabeya, Property 35809: operator https://adnilecruises.com/cruise/storia-the-dahabeya/ and Spanish edition. Official operator describes 6 Luxury Cabins (including a twin arrangement), Polaris Suite and Moonlight Suite, 18 guests total, and onboard plunge pool, sun deck, restaurant, lounge/bar, spa, gym, Wi-Fi, air conditioning and cabin safe. Direct unattended crawl disallowed by robots.txt; facts were reviewed from indexed official source.
- Sonesta Amirat Dahabeya II, Property 35817: official https://newsroom.sonesta.com/uncategorized/sonesta-announces-launch-of-sonesta-dahabeya-amirat-ii/ and https://www.sonesta.com/sonesta-dahabeya-amirat-ii/guest-cabins-and-suites. Five Executive Cabins and four Guest Suites, with cabin area 25 m2 reported; sun deck, restaurant, lounge bar, open-air Jacuzzi, massage, gym, cabin Wi-Fi, air conditioning, safe, minibar and elevator. Direct unattended crawl permitted for newsroom only. Sonesta operator pages vary between four- and seven-night itinerary descriptions; departure duration and dates are not certified.
- Iberotel Amara, Property 35810: operator https://partner.jazhotels.com/cruise/82 plus archived https://partner.jazhotels.com/Images/uploads/gallery/Downloads/new/060349Iberotel%20Amara%20Factsheet.pdf. The archived sheet reports 25 doubles, two singles, two suites, pool and other facilities. The newer PDF path https://storage.googleapis.com/jaz-prod/strapi/060349_Iberotel_Amara_Factsheet_fe49c81407/060349_Iberotel_Amara_Factsheet_fe49c81407.pdf contains branding and content for a DIFFERENT ship, Jaz Al Qassida. Amara data were recorded as historic and unverified; NO Amara amenity taxonomy terms or Units assigned.

Each ship now has one private _mad4b_cruise_public_web_amenities_evidence_v1 receipt with official URLs, source status, amenity keys, category descriptions, and all review warnings. No names were conflated across vessels or between Nour El Nil as fleet brand and individual boats.

## Cruise Amenities taxonomy

Extended the existing JetEngine amenities taxonomy with one bilingual Cruise Amenities / Servicios de crucero parent and 14 bilingual child terms: Sun Deck, Onboard Restaurant, Lounge Bar, Onboard Wi-Fi, Cabin Air Conditioning, In-Cabin Safe, Plunge Pool, Onboard Spa, Onboard Gym, Open-Air Jacuzzi, Cabin Minibar, Sun Deck Swimming Pool, Onboard Massage, Onboard Elevator.

30 new terms across 15 WPML EN/ES translation groups. Assigned 9 source-backed terms to Storia and 11 to Sonesta Amirat II. The 174 previous amenities terms and translations were unchanged. No public cruise-specific widget or filter was invented or activated.

## Five editorial cabin categories, all Draft

- Storia 71747: Luxury Cabin, reported six combined, including twin layout; subtype counts are unknown.
- Storia 71748: Polaris Suite, reported one.
- Storia 71749: Moonlight Suite, reported one.
- Sonesta 71750: Executive Cabin, reported five, 25 m2 cabins.
- Sonesta 71751: Guest Suite, reported four combined; Royal/Executive breakdown not assumed.

All are English-only Draft units linked to exact source Property through native JetEngine relation 223, with unit_kind=cabin or suite, unit_readiness=pending_review, unit_available=false. Operator category counts are recorded ONLY as historical source descriptions, not inventory. No unit_max_occupancy, unit_inventory_quantity, unit_start_price, sailing allotment, current availability, supplier contract or checkout authorization was created.

## Independent readback and rollback

Code: scripts/mad4b-cruise-operator-web-amenities-editorial-cli.php; supports plan, one-time apply and read-only verify. APPLY HAS ALREADY RUN; never repeat.
Prewrite backup: /opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/cruise-web-amenities-before.json
Independent readback: /opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/cruise-web-amenities-independent-after.json

Postwrite verification: error_count=0. Original 152 Unit records, 151 original relation 223 records, 174 native amenity terms and 32 hand-built Draft Tour Rates remained unchanged. Exactly five Draft units, five relations and 30 amenity terms were added. Published Tour/Property/Transport Rates=0; catalog_activated=false; CompanyCommercePolicy still unconfigured. CSS certification independently generated _mad4b_ccss_route_generation_v1 values 1427/1428/1429 on the three ship Properties because content/taxonomy changed, without pricing or inventory writes.

Next external evidence: ship-specific sailing dates and operator acceptance of the five mismatched sightseeing itineraries, booked cabin capacities, adult/child policy, verified available cabin allotment and signed tariffs. The web source evidence does not authorize payment or booking. PR #952 remains Draft.

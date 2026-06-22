<p align="center">
  <img src="images/tpay_logo.jpg" alt="Tpay" height="56">
</p>

<h1 align="center">Tpay przez Store API (headless)</h1>

<p align="center">Endpointy Store API do obsługi płatności Tpay w sklepach headless (Nuxt/PWA, aplikacja mobilna) — BLIK Level 0, lista banków (pay-by-link) i sprawdzanie statusu płatności.</p>

---

> 📄 Konfigurację wtyczki opisuje [główna instrukcja](index.md). Ten dokument zakłada, że wtyczka jest skonfigurowana, a metody Tpay są aktywne i przypisane do kanału sprzedaży.

## Po co to

Shopware udostępnia całą logikę sklepu przez **Store API**, więc warstwę zakupową można zbudować na dowolnym froncie. Standardowy redirect (karta, przelew) realizujesz natywnym `POST /store-api/handle-payment` — bramka zwraca `redirectUrl`. Wtyczka dokłada do tego **trzy własne endpointy** potrzebne w modelu headless:

| Endpoint | Metoda | Po co |
|---|---|---|
| `/store-api/cr/payment/blik` | `POST` | Zapłata kodem BLIK **bez przekierowania** (Level 0) |
| `/store-api/cr/payment-sub-methods` `/store-api/cr/payment-sub-methods/{paymentId}` | `GET` | Lista banków / sub-metod (pay-by-link, BNPL) |
| `/store-api/cr/payment/check` | `POST` | Sprawdzenie statusu płatności (np. po BLIK L0) |

## Uwierzytelnianie

Jak każdy endpoint Store API:

- Nagłówek **`sw-access-key`** — klucz dostępu kanału sprzedaży (panel admina → kanał sprzedaży → *Klucz dostępu API*).
- Nagłówek **`sw-context-token`** — token kontekstu klienta/sesji (z `GET /store-api/context` lub zwracany przy operacjach na koszyku). Wymagany dla `…/blik` i `…/check` (dozwolony gość).
- `Content-Type: application/json` dla żądań `POST`.

---

## 1. BLIK Level 0 — `POST /store-api/cr/payment/blik`

Tworzy zamówienie z bieżącego koszyka i wysyła transakcję BLIK do Tpay na podstawie kodu podanego w sklepie — klient nie jest przekierowywany do bramki.

**Body:**

| Pole | Typ | Wymagane | Opis |
|---|---|---|---|
| `paymentMethodId` | string (UUID) | ✅ | ID metody płatności **BLIK** (Tpay) |
| `blikCode` | string | ✅ | Dokładnie **6 cyfr** (spacje są usuwane) |
| `finishUrl` | string | — | URL powrotu po sukcesie |
| `errorUrl` | string | — | URL powrotu po błędzie |
| `customFields` | object | — | Własne pola zamówienia (klucze z prefiksem `crehler_` są ignorowane) |

**Odpowiedź:**

| Pole | Typ | Opis |
|---|---|---|
| `success` | bool | Czy transakcja została przyjęta |
| `orderId` | string \| null | ID utworzonego zamówienia |
| `redirectUrl` | string \| null | Ustawione tylko, gdy bramka wymaga dodatkowego kroku (np. 3DS); w typowym L0 jest `null` |
| `error` | string \| null | Komunikat błędu (np. `Invalid BLIK code format`) |

```bash
curl -X POST 'https://twoj-sklep.pl/store-api/cr/payment/blik' \
  -H 'sw-access-key: SWSCXXXXXXXX' \
  -H 'sw-context-token: <token>' \
  -H 'Content-Type: application/json' \
  -d '{ "paymentMethodId": "0190…", "blikCode": "777654" }'
```

> ⚠️ Endpoint jest **rate-limitowany** per token kontekstu — po przekroczeniu limitu zwraca **HTTP 429**. Kod inny niż 6 cyfr jest odrzucany **przed** utworzeniem zamówienia (brak „osieroconych" zamówień).

Po `success: true` z pustym `redirectUrl` odpytuj status endpointem **`POST /store-api/cr/payment/check`** (sekcja 3 poniżej), aż klient potwierdzi płatność w aplikacji bankowej.

---

## 2. Lista banków / sub-metod — `GET /store-api/cr/payment-sub-methods`

Zwraca dostępne sub-metody (banki dla pay-by-link, ewentualnie BNPL) — do zbudowania listy wyboru banku we własnym froncie.

- `GET /store-api/cr/payment-sub-methods/{paymentId}` — dla wskazanej metody płatności,
- `GET /store-api/cr/payment-sub-methods` — dla metody aktualnie wybranej w kontekście.

**Parametry zapytania:**

| Parametr | Typ | Domyślnie | Opis |
|---|---|---|---|
| `paymentValue` | int | `10000` | Kwota w **groszach** (10000 = 100,00 PLN). Część sub-metod (np. raty/BNPL) zależy od kwoty. |

**Odpowiedź** — kolekcja elementów:

| Pole | Typ | Opis |
|---|---|---|
| `name` | string | Nazwa banku / sub-metody |
| `providerId` | string | Identyfikator sub-metody po stronie Tpay (przekazywany przy płatności) |
| `shopwareId` | string | Identyfikator po stronie Shopware |
| `mediaUrl` | string | URL logo banku |

```bash
curl 'https://twoj-sklep.pl/store-api/cr/payment-sub-methods?paymentValue=24999' \
  -H 'sw-access-key: SWSCXXXXXXXX'
```

### Ustawienie wybranego banku

Wybór ustawiasz **wyłącznie przez context switch** — tak samo jak natywny `paymentMethodId`. Dołóż pole `paymentSubMethod` (wartość = **`providerId`** z listy sub-metod) do `PATCH /store-api/context`:

```bash
curl -X PATCH 'https://twoj-sklep.pl/store-api/context' \
  -H 'sw-access-key: SWSCXXXXXXXX' \
  -H 'sw-context-token: <token>' \
  -H 'Content-Type: application/json' \
  -d '{ "paymentMethodId": "<id metody Przelew online>", "paymentSubMethod": "<providerId banku>" }'
```

Zachowanie jest takie jak natywnej metody płatności:

- **Gość:** zapis w bieżącym kontekście (sesji).
- **Zalogowany:** zapis w kontekście **i** zapamiętanie przy koncie. Przy kolejnym zamówieniu — gdy kontekst nie ma jeszcze wyboru — handler **sam** sięgnie po zapamiętany bank z konta (parytet z `paymentMethodId`, który Shopware przywraca po zalogowaniu). Nie musisz nic ponawiać.

Odczyt bieżącego wyboru (np. do pre-zaznaczenia w UI): **`GET /store-api/customer/cr/payment-sub-method`** — zwraca wartość z sesji, a w jej braku z konta zalogowanego klienta. To jedyny endpoint sub-metody klienta; **dedykowanego endpointu zapisu nie ma** — całość idzie przez context (jak natywnie).

Po ustawieniu wyboru inicjujesz płatność standardowym `POST /store-api/handle-payment` — handler odczyta wybrany bank i przekieruje klienta wprost do niego.

---

## 3. Status płatności — `POST /store-api/cr/payment/check`

Sprawdza bieżący status płatności zamówienia — używane głównie do odpytywania po BLIK Level 0 (oczekiwanie na potwierdzenie w aplikacji bankowej).

**Body:**

| Pole | Typ | Wymagane | Opis |
|---|---|---|---|
| `orderId` | string | ✅ | ID zamówienia (np. z odpowiedzi endpointu BLIK) |

**Odpowiedź** (`cr_payment_check_status`):

| Pole | Typ | Opis |
|---|---|---|
| `status` | bool | `true` = opłacone |
| `waiting` | bool | `true` = oczekuje na potwierdzenie |
| `failed` | bool | `true` = nieudane / odrzucone |

```bash
curl -X POST 'https://twoj-sklep.pl/store-api/cr/payment/check' \
  -H 'sw-access-key: SWSCXXXXXXXX' \
  -H 'sw-context-token: <token>' \
  -H 'Content-Type: application/json' \
  -d '{ "orderId": "0190…" }'
```

---

## Typowy przepływ headless

**BLIK Level 0:**

1. Zbuduj koszyk standardowym Store API (`/store-api/checkout/cart`, dodanie pozycji).
2. `POST /store-api/cr/payment/blik` z `paymentMethodId` (BLIK) i `blikCode` → otrzymujesz `orderId` (i ewentualnie `redirectUrl`).
3. Jeśli jest `redirectUrl` — przekieruj klienta; w przeciwnym razie **odpytuj** `POST /store-api/cr/payment/check` co kilka sekund, aż `status: true` (opłacone) lub `failed: true`.

**Przelew (pay-by-link):**

1. `GET /store-api/cr/payment-sub-methods` → pokaż listę banków (`name` + `mediaUrl`).
2. Zapisz wybór klienta — `PATCH /store-api/context` z `paymentSubMethod` = `providerId` banku (patrz [Ustawienie wybranego banku](#ustawienie-wybranego-banku)).
3. Finalizuj płatność standardowym `POST /store-api/handle-payment` → bramka zwraca `redirectUrl` wprost do wybranego banku/operatora.

---

## Wsparcie

Pytania o integrację headless? **[support@crehler.com](mailto:support@crehler.com)**

<p align="center"><sub>Bramka płatności <strong>Tpay by CREHLER</strong> · <a href="https://crehler.com/">crehler.com</a></sub></p>

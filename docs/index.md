<p align="center">
  <img src="images/tpay_logo.jpg" alt="Tpay" height="72">
</p>

<h1 align="center">Tpay dla Shopware 6 — dokumentacja</h1>

<p align="center">Integracja bramki płatniczej <strong>Tpay</strong> ze sklepem <strong>Shopware 6</strong> — <strong>Bramka płatności Tpay by CREHLER</strong>.<br>
BLIK (w tym Level 0), karty z formularzem w checkout, pay-by-link, raty oraz zwroty z panelu administratora.</p>

---

## Od czego zacząć

| Dokument | Opis |
|---|---|
| 📦 **[Instalacja](instalacja.md)** | Dwie metody: Composer albo paczka ZIP — krok po kroku. |
| ⚙️ **[Konfiguracja](konfiguracja.md)** | Dane z panelu Tpay, przypisanie metod do kanału sprzedaży, opcje karty, dane testowe (sandbox). |
| 🧩 **[Store API (headless)](store-api.md)** | Endpointy dla sklepów headless: BLIK Level 0, lista banków, status płatności. |
| ↩️ **[Zwroty płatności](zwroty.md)** | Pełne i częściowe zwroty z poziomu panelu Shopware. |

## Najważniejsze funkcje

- 🟢 **BLIK Level 0** — płatność kodem BLIK w sklepie, bez przekierowania.
- 💳 **Karta** — domyślnie redirect do Tpay, opcjonalnie formularz osadzony w checkout (3-D Secure, zapisane karty).
- 🏦 **Pay-by-link** — wybór banku z listy; wybrany bank zapamiętywany na kolejne zamówienia.
- ↩️ **Zwroty** — pełne i częściowe, wprost z panelu Shopware.
- 🧩 **Headless** — pełne wsparcie Store API obok klasycznego Storefrontu.

## Wymagania (skrót)

Shopware **6.6/6.7**, PHP **8.2–8.5**, aktywne konto Tpay z **Open API**, waluta **PLN**. Szczegóły i instalacja: **[Instalacja](instalacja.md)**.

---

## Wsparcie

Masz pytanie? Napisz do nas: **[support@crehler.com](mailto:support@crehler.com)**

<p align="center"><sub>Bramka płatności <strong>Tpay by CREHLER</strong> · <a href="https://crehler.com/">crehler.com</a></sub></p>

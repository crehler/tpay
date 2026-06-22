<p align="center">
  <img src="src/Resources/public/icons/tpay_logo.jpg" alt="Tpay" height="80">
</p>

<h1 align="center">Tpay dla Shopware 6 — by CREHLER</h1>

<p align="center">
  Integracja bramki płatniczej <strong>Tpay</strong> ze sklepem <strong>Shopware 6</strong>.<br>
  BLIK (w tym Level&nbsp;0), karty z formularzem w checkout, pay-by-link, raty oraz zwroty z panelu administratora.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Shopware-6.6%20%7C%206.7-189EFF?logo=shopware&logoColor=white" alt="Shopware 6.6 | 6.7">
  <img src="https://img.shields.io/badge/PHP-8.2%20–%208.5-777BB4?logo=php&logoColor=white" alt="PHP 8.2 – 8.5">
  <img src="https://img.shields.io/badge/wersja-6.0-success" alt="Wersja 6.0">
  <img src="https://img.shields.io/badge/by-CREHLER-ff5c00" alt="by CREHLER">
</p>

---

## Czym jest Shopware?

[Shopware 6](https://www.shopware.com/) to nowoczesna platforma e-commerce open source, na której działają tysiące sklepów internetowych w Europie. Daje pełną kontrolę nad wyglądem sklepu, procesem zakupowym i integracjami, a jej modułowa architektura pozwala rozszerzać sklep o wtyczki — takie jak ta integracja Tpay.

Shopware działa też w modelu **headless** — całą logikę sklepu udostępnia przez **Store API**, dzięki czemu warstwę zakupową można zbudować na dowolnym froncie (np. aplikacja Nuxt/PWA, aplikacja mobilna), niezależnie od wbudowanego Storefrontu. **Ta wtyczka obsługuje oba światy** — działa zarówno w klasycznym Storefroncie, jak i w pełni przez Store API (w tym dedykowany endpoint dla BLIK Level 0), więc sprawdzi się także w sklepach headless.

## O wtyczce

**Bramka płatności Tpay by CREHLER** podłącza polską bramkę płatniczą [Tpay](https://tpay.com/) do Twojego sklepu Shopware 6. Klient płaci tak, jak lubi — kodem BLIK bez wychodzenia ze sklepu, kartą, przelewem pay-by-link albo na raty — a sklep automatycznie otrzymuje potwierdzenie płatności i aktualizuje status zamówienia. Zwroty wykonasz jednym kliknięciem z panelu Shopware.

## ✨ Funkcje

- 🟢 **BLIK Level 0** — klient wpisuje 6-cyfrowy kod BLIK bezpośrednio w sklepie i płaci **bez przekierowania** do bramki.
- 💳 **Płatność kartą** — domyślnie z przekierowaniem do bezpiecznej strony Tpay, a **opcjonalnie** z formularzem karty **osadzonym wprost w checkout** sklepu (dane szyfrowane kluczem RSA Tpay), z obsługą **3-D Secure**.
- 🔁 **Zapisane karty (tokeny)** — klient może zapisać kartę do kolejnych zakupów; tokeny przechowywane w formie zaszyfrowanej.
- 🏦 **Pay-by-link (przelewy bankowe)** — klient wybiera swój bank z listy i płaci szybkim przelewem online; **wybrany bank zostaje zapamiętany**, więc przy kolejnych zamówieniach płaci jednym kliknięciem.
- 📅 **Płatności odroczone / raty** — wsparcie dla metod ratalnych Tpay.
- ↩️ **Zwroty z panelu administratora** — zwroty pieniędzy (płatności) klientowi, w całości lub częściowo, bez logowania do panelu Tpay.
- 🎚️ **Konfigurowalny checkout** — możliwość osadzenia formularza karty oraz wyboru pozycji pola BLIK (w checkout / osobna strona / ukryte).

## 💳 Metody płatności

| | Metoda | Opis |
|---|---|---|
| <img src="src/Resources/public/icons/blik.png" height="28"> | **BLIK** | Płatność kodem BLIK w sklepie (Level 0) lub z przekierowaniem. |
| <img src="src/Resources/public/icons/card.png" height="28"> | **Karta** | Visa / Mastercard — formularz w checkout lub redirect, 3-D Secure, zapis karty. |
| <img src="src/Resources/public/icons/bank.png" height="28"> | **Przelew (pay-by-link)** | Wybór banku z listy i szybki przelew online. |

## ✅ Wymagania

| Komponent | Wersja |
|---|---|
| Shopware | 6.6.x lub 6.7.x |
| PHP | 8.2, 8.3, 8.4 lub 8.5 |
| Konto Tpay | aktywne konto z dostępem do **Open API** |
| Waluta | sklep musi obsługiwać **PLN** |

## 🚀 Szybka instalacja

> To skrócony przebieg (Composer). Pełna instrukcja — **Composer oraz paczka ZIP**, krok po kroku — znajduje się w **[docs/instalacja.md](docs/instalacja.md)**.

**1. Zainstaluj wtyczkę przez Composer:**

```bash
composer require crehler/tpay
```

**2. Aktywuj w Shopware:**

```bash
bin/console plugin:refresh
bin/console plugin:install --activate CrehlerTpay
bin/console cache:clear
```

**3. Uzupełnij dane w konfiguracji wtyczki** — panel admina → **Rozszerzenia → Moje rozszerzenia → Bramka płatności Tpay by CREHLER → Skonfiguruj**:

- **Client ID** i **Client Secret** — z panelu Tpay: *Integracja → API → Klucze Open API*,
- **Kod bezpieczeństwa powiadomień** — *Ustawienia → Powiadomienia → Bezpieczeństwo* (wymagany, by potwierdzenia płatności były akceptowane),
- *(opcjonalnie)* **Klucz publiczny Cards API** — tylko jeśli chcesz osadzić formularz karty w checkout,
- na koniec kliknij **„Testuj połączenie"**, aby zweryfikować dane.

> 💡 Do testów włącz **Tryb sandbox** i podaj dane z testowego konta Tpay.

📚 **[Pełna dokumentacja → `docs/`](docs/index.md)** — konfiguracja krok po kroku, metody płatności, webhooki, zwroty i rozwiązywanie problemów (ze zrzutami ekranu).

## 🛟 Wsparcie

Masz pytanie lub problem? Napisz do nas: **[support@crehler.com](mailto:support@crehler.com)**

---

## O CREHLER

<p align="center">
  <a href="https://crehler.com/"><strong>CREHLER</strong></a> — Twój partner w e-commerce.
</p>

Tworzymy i rozwijamy sklepy internetowe na **Shopware**, budujemy dedykowane integracje, wtyczki i headless‑owe frontendy (Nuxt). Robimy integracje **ERP**, **WMS**, **płatności** i **dostaw**, a także customowe **konfiguratory**, **kalkulatory** i inne rozszerzenia szyte na miarę Twojego sklepu.

Potrzebujesz wdrożenia, integracji albo dedykowanej funkcji w swoim sklepie? **[Porozmawiajmy → crehler.com](https://crehler.com/)**

---

## 📄 Licencja

Oprogramowanie własnościowe (proprietary). © Crehler Sp. z o.o. Wszelkie prawa zastrzeżone.

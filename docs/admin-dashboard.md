# Dashboard administratora — początek Etapu 3

## Uruchomienie i zakres

Korzystaj z istniejącej konfiguracji PHP/PDO opisanej w [instrukcji uruchomienia](php-foundation.md). Zaloguj się testowym administratorem `admin@cuttime.test` z hasłem `password` (dane demonstracyjne z SQL). Logowanie kieruje na `/admin.php`.

W pierwszym kroku dodano dashboard i wspólną nawigację. W kolejnym kroku udostępniono [CRUD kategorii usług](admin-categories.md). Moduły Usługi, Pracownicy, Dostępność pracowników i Rezerwacje nadal mają etykietę „Wkrótce” i nie prowadzą do nieistniejących adresów. Nie zaimplementowano zarządzania tymi modułami ani rezerwowania wizyt. Etap 3 jest nadal w trakcie realizacji.

## Źródła statystyk

| Licznik | Źródło i znaczenie |
|---|---|
| Klienci | `COUNT(*)` z `users` z rolą `client`, także konta nieaktywne. |
| Pracownicy | `COUNT(*)` z `employees`, także profile nieaktywne. Samo konto z rolą `employee` bez profilu nie zwiększa tej liczby. |
| Usługi | `COUNT(*)` z `services`, także usługi nieaktywne. |
| Wszystkie rezerwacje | `COUNT(*)` z `reservations`, wszystkie daty i statusy. |
| Rezerwacje na dzisiaj | `reservation_date` równa dzisiejszej dacie w `Europe/Warsaw`, wszystkie statusy, także `cancelled`. |
| Oczekujące rezerwacje | `status = 'pending'`, wszystkie daty. |

Jedno zapytanie prepared statement z niezależnymi podzapytaniami nie mnoży rekordów przez złączenia. Parametry roli, statusu i daty ustala serwer. Data dla zapytania i nagłówka widoku jest obliczana raz; ustawienia strefy PHP/MySQL nie zmieniają znaczenia „dzisiaj”. Odczyt następuje przy każdym wejściu na stronę, bez stałych liczników i bez cache statystyk.

Po imporcie przykładowego SQL są 2 klientów, 2 profile pracowników, 6 usług, 8 rezerwacji i 2 rezerwacje oczekujące. Rezerwacje testowe mają stałe daty z września 2026; poza tymi datami licznik na dzisiaj prawidłowo wynosi 0. Błąd odczytu bazy nie jest zastępowany zerami: obsługuje go wspólny handler błędów.

## Kod i zabezpieczenia

- `public/admin.php` wywołuje istniejące `require_role('admin')` przed zapytaniami dashboardu i HTML. Gość trafia do logowania, klient i pracownik do swoich stron. GET/POST nie mogą zmienić roli; liczą się aktualne uprawnienia i aktywność z bazy.
- Dashboard obsługuje tylko GET. Inne metody po sprawdzeniu dostępu zwracają 405. Nie ma operacji zmieniających dane.
- `includes/admin-dashboard.php` używa istniejącego `db()` i pobiera statystyki. Opcjonalne PDO i czas służą testom; kod nie otwiera drugiego połączenia.
- `includes/account-view.php` został rozbudowany o wariant administracyjny. Zachowuje wspólny nagłówek, flash, `e()`, nagłówki HTML, `session.js` oraz nawigację konta i wylogowanie przez POST z istniejącym CSRF.
- `includes/admin-navigation.php` przechowuje jedną mapę modułów. Nowy moduł będzie wymagał własnego kontrolera chronionego przez ten sam `require_role('admin')`; sam link nie chroni dostępu.
- `includes/admin-dashboard-view.php` wyświetla wyłącznie przygotowane dane z użyciem `e()`. Szablony pozostają poza `public/`.
- Wspólny `public/css/auth.css` zawiera responsywne style panelu: 3, 2 lub 1 kolumna statystyk, zawijana nawigacja i widoczny fokus.

Nie zmieniono SQL, ERD, kont testowych, PDO, sesji, logowania, autoryzacji ani CSRF. Nie dodano lokalnych sekretów do repozytorium.

## Sprawdzanie

```text
php tests/foundation.php
php tests/registration.php
php tests/login.php
php tests/authorization.php
php tests/profile.php
php tests/http.php
```

Pełne testy wymagają `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD` i osobnego serwera testowego zgodnie z [instrukcją testów HTTP](stage2-audit.md#powtarzanie-testów). Bez DSN część bazowa zgłasza `SKIP`.

Rozszerzono istniejący `tests/http.php`: sprawdza liczniki po imporcie, dzisiejszą datę i sąsiednie dni, status anulowany, dane nieaktywne, profil pracownika a samo konto, zmianę liczby klientów po rejestracji, zera dla pustych zbiorów, strefę Polski zimą i latem, XSS, błędne metody i parametry oraz bezpieczną awarię zapytania. Istniejąca macierz ról obejmuje panel oraz dostęp po wylogowaniu. Test tworzy własny losowy schemat i kopię aplikacji, a po zakończeniu je usuwa; nie korzysta z lokalnej konfiguracji użytkownika.

Weryfikacja 02.11.2026 na PHP 8.4.27 i odizolowanej MariaDB 11.4.5:

- 85 testów PHP oraz 129 kontroli HTTP zakończonych powodzeniem, bez pominięć.
- Poprawna składnia wszystkich 33 plików PHP.
- Edge: dashboard przy szerokościach 1280, 768, 700, 390 i 320 px, bez przewijania w poziomie. Sprawdzono układ 3/2/1 kolumn, jeden widoczny nagłówek h1 i odnośniki/przyciski wysokości co najmniej 44 px.
- Powrót przez historię przeglądarki po wylogowaniu prowadzi do logowania i nie odsłania danych administratora.
- Brak nowych sekretów i zmian schematu bazy; wykorzystano mechanizmy Etapu 2.

Testy nie zastępują konfiguracji własnego serwera. W repozytorium nie ma lokalnego `config/database.local.php`; należy go przygotować zgodnie z istniejącą instrukcją. Nie testowano oddzielnie MySQL, docelowego HTTPS ani fizycznego telefonu.

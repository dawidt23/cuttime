# Dopracowanie i kontrola Etapu 3 — 6–7 listopada 2026

Przejrzano cały istniejący panel: dashboard, kategorie, usługi, pracowników, przypisywanie usług, tygodniową dostępność i rezerwacje. Zachowano wcześniejsze niezacommitowane zmiany modułu rezerwacji. Nie dodano nowego modułu biznesowego ani funkcji Etapu 4.

## Znalezione problemy i poprawki

| Ustalenie | Wykonana poprawka |
|---|---|
| Obliczanie granic stron powtarzało się w czterech listach, a HTML nawigacji w pięciu widokach. | Wspólny `admin_pagination()` i `render_admin_pagination()` w `includes/admin-pagination.php`; numer strony sprawdza `admin_page_number()` w istniejącym module formularzy. |
| Przy większej liczbie stron można było przechodzić tylko o jedną stronę. | Dodano pierwszą/ostatnią stronę tam, gdzie nie powielają poprzedniej/następnej, oraz zakres pokazanych wyników. |
| Podsumowanie błędów powtarzało się w pięciu widokach, obejmujących sześć rodzajów formularzy. | Jeden `includes/admin-errors-view.php`: bezpieczny HTML, `role="alert"` i fokus na błędzie. |
| Filtry rezerwacji oznaczały błędne pole, ale komunikat znajdował się tylko w podsumowaniu. | Komunikaty przy polach daty, statusu i pracownika oraz poprawne `aria-describedby`. |
| Lista pracowników pobierała cały opis, przez co długie teksty rozciągały tabelę. | `LEFT(description, 160)` w zapytaniu listy i wyjaśnienie w podpisie tabeli. Formularz edycji zachowuje pełny opis. |
| Dashboard zapowiadał moduły, które już działały. | Zmieniono nieaktualny tekst pod statystykami. Same zapytania i liczby były poprawne. |
| Na ekranie 320 px główna nawigacja zajmowała sześć wierszy; aktywna zakładka usług/dostępności pracownika nie była wyróżniona kolorem. | Dwie kolumny na małych ekranach i styl istniejącego `aria-current="page"` w nawigacji pracownika. |

W sprawdzonych scenariuszach nie wykryto brakującego auth/CSRF, niebezpiecznej konkatenacji danych w SQL ani brakujących podstawowych reguł walidacji. Poprawne reguły pól, transakcje i obsługę FK pozostawiono. Nie zastąpiono ich nowym mechanizmem.

## Paginacja

Kategorie, usługi, pracownicy, wybór pracownika w przypisaniach/dostępności i rezerwacje korzystają z limitu **20 rekordów**. Zapytania liczą wyniki przez `COUNT(*)`, a rekordy pobierają przez SQL `LIMIT :limit OFFSET :offset` z parametrami całkowitymi. Nie ma dzielenia pełnej listy w JavaScript.

Niepoprawny numer strony daje 400, brak numeru oznacza 1, a poprawny numer przekraczający liczbę stron jest ograniczany do ostatniej. Puste wyniki są obsłużone. Sortowanie kończy się ID, co rozstrzyga remisy.

W rezerwacjach data, status i pracownik łączą się przez `AND`. Zapytania COUNT i listy mają ten sam WHERE. Wszystkie linki paginacji, szczegóły i powrót zachowują zatwierdzone filtry; zastosowanie formularza filtrów rozpoczyna listę od strony 1. Widoki dostępności jednej osoby i pełnego zestawu przypisanych usług pozostają pojedynczymi formularzami; nie dzielimy zestawu synchronizacji na strony, aby nie usuwać przypisań niewidocznych na bieżącej stronie.

## Walidacja, uprawnienia i ochrona operacji

| Moduł / kontroler | Sprawdzone zachowanie |
|---|---|
| `public/admin.php` | Wspólne `require_role('admin')`, tylko GET, statystyki z bazy. |
| `public/admin-categories.php` | Nazwa wymagana, białe znaki i typy danych, limit 100 znaków, opis do 2000 znaków, aktywność 0/1, unikalność nazwy, poprawne ID, potwierdzenie usunięcia. |
| `public/admin-services.php` | Nazwa do 150 znaków, opis do 2000, istniejąca kategoria, cena 0–99999999,99 z maks. 2 miejscami dziesiętnymi bez zaokrąglania floatów, czas 1–1440 pełnych minut, aktywność 0/1. |
| `public/admin-employees.php` | Wspólna walidacja danych konta i hasła, e-mail i jego unikalność, polski telefon, limity długości, rola employee ustawiana serwerowo, poprawne ID i rola powiązanego konta. Konto/profil zapisywane transakcyjnie. |
| `public/admin-employee-services.php` | Lista poprawnych, istniejących ID usług, usunięcie duplikatów, limit 500, potwierdzenie i znacznik pełnego formularza; synchronizacja w transakcji pod blokadą pracownika. |
| `public/admin-availability.php` | Dzień 1–7, poprawny czas HH:MM lub HH:MM:SS, początek przed końcem w tym samym dniu, własność wpisu; nakładanie przedziałów odrzucane również przy równoczesnych zapisach. |
| `public/admin-reservations.php` | ID i istnienie rezerwacji/pracownika, rzeczywista data, cztery statusy zgodne z ENUM, typy filtrów i stron, potwierdzenie statusu oraz blokada zapisu nieaktualnego formularza. |

Wszystkie siedem kontrolerów przed obsługą żądania wywołuje **istniejące `require_role('admin')`**. Rola i aktywność są odczytywane z bazy, a nie przyjmowane z GET/POST. Dotyczy to każdego wariantu akcji, także POST bez wcześniejszego otwarcia formularza. Nie ma dodatkowych publicznych endpointów zapisu.

Wszystkie mutacje wymagają POST i istniejącego CSRF w `admin_form_request()`: tworzenie, edycja, usuwanie, aktywacja/dezaktywacja, przypisania, dostępność i status rezerwacji. GET pokazuje dane lub potwierdzenie. Usuwanie wymaga dodatkowego potwierdzenia po stronie PHP; osobne akcje aktywacji/dezaktywacji, zmiana statusu rezerwacji i zapis zestawu usług również mają potwierdzenia. Aktywność kategorii/usługi można też wybrać w formularzu edycji. POST ma limit 32 KiB.

Zapytania z danymi użytkownika używają PDO prepared statements. Zmienne fragmenty SQL powstają tylko ze stałych map kolumn lub liczby placeholderów. Dane przy wyświetlaniu przechodzą przez istniejące `e()`; HTML escaping nie zastępuje wiązania parametrów SQL. Awaria bazy trafia do wspólnego handlera zamiast udawać sukces lub puste wyniki. PHP musi mieć wyłączone wyświetlanie błędów również przed bootstrapem, zgodnie z [konfiguracją projektu](php-foundation.md).

## Zależności i historia

- **Kategoria z usługami:** usunięcie blokowane, także gdy usługi są nieaktywne. FK RESTRICT chroni też sytuację przypisania usługi po wstępnym sprawdzeniu.
- **Usługa z rezerwacjami:** każda rezerwacja, również anulowana/zakończona, blokuje DELETE. Interfejs proponuje dezaktywację. FK chroni wyścig między SELECT i DELETE.
- **Usługa bez rezerwacji:** usunięcie wymaga potwierdzenia informującego o usunięciu powiązań `employee_services` przez CASCADE; pracownicy pozostają.
- **Pracownik:** brak fizycznego DELETE. Dezaktywacja konta i profilu w jednej transakcji zachowuje rezerwacje, usługi i dostępność; kolejne żądanie sesji traci dostęp.
- **Przypisania i dostępność:** zmiany dotyczą wyłącznie odpowiednich relacji/wpisów. Nie usuwają usług, pracowników ani historycznych rezerwacji.
- **Rezerwacja:** administrator zmienia wyłącznie status. Daty, godziny i powiązania pozostają bez zmian. Tworzenie wizyt i reguły konfliktów podczas planowania należą do Etapu 4.

## Dashboard

`includes/admin-dashboard.php` wykonuje przygotowane zapytanie z sześcioma rzeczywistymi COUNT: kont klientów, profili pracowników, usług, wszystkich rezerwacji, rezerwacji na dzisiaj i oczekujących. Dzień wyznaczany jest w strefie `Europe/Warsaw`. Testy zmieniają rekordy i sprawdzają wynik, także przy pustej bazie i na granicach dat. W widoku nie ma przykładowych liczb wpisanych na sztywno.

## Wyniki testów

Środowisko: PHP **8.4.27**, odizolowana MariaDB **11.4.5**, kopia aplikacji w katalogu tymczasowym. Testy nie używały lokalnej bazy ani konfiguracji użytkownika.

- **85 testów PHP** zakończonych powodzeniem: fundament 17, rejestracja 16, logowanie 13, autoryzacja 26, profil 13.
- **1223 kontrole HTTP**, bez błędów i pominięć. Pełna regresja Etapu 2 oraz wszystkich modułów Etapu 3: role, metody, CSRF, wadliwe typy, puste/białe/za długie dane, XSS, unikalność, transakcje, FK, równoczesne zapisy i bezpieczne błędy.
- Nowy `tests/admin-polish-http.php` dodaje po 45 rekordów do odizolowanych list. Sprawdza trzy strony, bardzo wysoki numer strony, poprawne zakresy, wszystkie linki nawigacji i filtry rezerwacji, pełny opis w edycji, sześć pustych formularzy i dostępne komunikaty błędów. Własne rekordy usuwa po próbach.
- **Składnia wszystkich 63 plików PHP poprawna**; `git diff --check` bez błędów.
- **Edge: 30 widoków × 3 szerokości (1280, 390 i 320 px), czyli 90 układów**. Brak poziomego przepełnienia całej strony; tabele przewijają się w swoich kontenerach. Pola mają tekst co najmniej 16 px, sprawdzone akcje/nawigacja/potwierdzenia obszar wysokości co najmniej 44 px. Każdy widok ma aktywną pozycję nawigacji.
- W przeglądarce wysłano sześć niepoprawnych formularzy: każde podsumowanie błędów wystąpiło raz i dostało fokus. Przejścia pierwsza/ostatnia/następna oraz powrót ze szczegółów zachowały filtry. Po wylogowaniu przycisk Wstecz nie udostępnił panelu.
- Przegląd zmian i plików nie wykazał dodania sekretów, lokalnych konfiguracji ani drugiego PDO/auth/CSRF. Schemat SQL, ERD i dane testowe nie zostały zmienione.

Pełny zestaw uruchamia się przez `php tests/http.php` po skonfigurowaniu testowych `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER` i `CUTTIME_TEST_PASSWORD`, zgodnie z [instrukcją testów](stage2-audit.md#powtarzanie-testów). Konto testowe bazy musi pozwalać na tworzenie/usuwanie własnych schematów, tabel, triggerów i używanie transakcji. Pliku `tests/admin-polish-http.php` nie uruchamia się osobno.

Nie weryfikowano osobnego MySQL, rzeczywistej bazy użytkownika, docelowego serwera HTTPS, fizycznych telefonów ani innych przeglądarek. Nie wykonano audytu całej historii Git. Brak błędów w tych testach nie jest gwarancją braku wszystkich możliwych usterek.

Po wznowieniu 7 listopada potwierdzono zapis wcześniejszych wyników testów i zgodność zmienionych plików aplikacji z kopią sprawdzoną w przeglądarce. Ponownie sprawdzono składnię 63 plików PHP, odnośniki dokumentacji, poprawność różnic i brak dodanych konfiguracji lokalnych lub rozpoznawalnych sekretów. Nie pozostała niedokończona funkcja z tego zadania; domknięto kontrolę końcową i raport.

## Pliki tego kroku

Nowe:

- `includes/admin-pagination.php`
- `includes/admin-errors-view.php`
- `tests/admin-polish-http.php`
- `docs/stage3-audit.md`

Zmodyfikowane względem początku tego zadania:

- `includes/admin-forms.php`
- `includes/admin-categories.php`, `includes/admin-categories-view.php`
- `includes/admin-services.php`, `includes/admin-services-view.php`
- `includes/admin-employees.php`, `includes/admin-employees-view.php`
- `includes/admin-reservations.php`, `includes/admin-reservations-view.php`
- `includes/admin-employee-relations.php`, `includes/admin-employee-context-view.php`
- `includes/admin-dashboard-view.php`
- `public/admin-categories.php`, `public/admin-services.php`, `public/admin-employees.php`
- `public/css/auth.css`
- `tests/http.php`
- `README.md`, `docs/admin-employees.md`, `docs/admin-reservations.md`

## Ocena zgodności

W sprawdzonym środowisku Etap 3 spełnia wymienione wymagania ocen 2–5: logowanie administratora, dashboard z bazą, CRUD kategorii/usług, pracownicy, N:M usług, dostępność, aktywność, rezerwacje i ich statusy/filtry, walidacja, potwierdzenia, ochrona operacji, paginacja, zachowanie zależności i wspólny kod. Nie znaleziono brakującego modułu w podanym zakresie. Funkcje klienta i pracownika z Etapu 4 pozostają poza zadaniem.

Nie wykonano `git commit`, `git push` ani merge.

# Zarządzanie pracownikami — Etap 3

Moduł `/admin-employees.php` jest dostępny w istniejącej nawigacji administratora, po standardowej [konfiguracji PHP/PDO](php-foundation.md). Obejmuje listę (20 rekordów na stronę), dodawanie nowych pracowników, edycję danych i zmianę aktywności. Nie obejmuje fizycznego usuwania, zmiany haseł, przypisywania usług ani edycji dostępności.

## Relacja users–employees

`employees.user_id` jest wymaganym, unikalnym FK do `users.id`: użytkownik ma **zero lub jeden** profil pracownika, a profil należy do dokładnie jednego użytkownika. Imię, nazwisko, e-mail, telefon, hash hasła i rola znajdują się w `users`. Opis i aktywność profilu znajdują się w `employees`. Dane kontaktowe nie są powielane. ID w adresie modułu oznacza `employees.id`, nie `users.id`.

SQL nie wymusza zgodności roli z profilem. Moduł tworzy wyłącznie rolę `employee`, a przed edycją i zmianą aktywności ponownie sprawdza rolę konta w bazie. Profil powiązany z inną rolą jest widoczny na liście z informacją o blokadzie; operacje zwracają bezpieczny komunikat (409). Moduł nie zmienia automatycznie ról istniejących kont.

## Dodawanie i edycja

- Dodawanie wymaga imienia, nazwiska, e-maila, telefonu, hasła, jego powtórzenia i statusu; opis jest opcjonalny.
- Używa wspólnego `validate_registration()` oraz `password_hash(..., PASSWORD_BCRYPT)`. Rola `employee` jest stałą w INSERT; POST z `role=admin`, ID lub innymi dodatkowymi polami nie zmienia celu ani roli.
- Konto i profil powstają w jednej transakcji InnoDB. ID konta pochodzi z `lastInsertId()`. Awaria drugiego zapisu wycofuje też pierwszy: nie zostaje konto bez profilu.
- To formularz **nowego konta**. E-mail już należący do klienta, administratora lub pracownika zostaje odrzucony. Nie przejmuje się kont i nie dołącza automatycznie profili do kont istniejących bez profilu.
- Edycja korzysta z `validate_user_details()` i pozwala zmienić wyłącznie imię, nazwisko, e-mail, telefon oraz opis. Nie zmienia hasła, roli, ID ani aktywności, nawet jeżeli przesłano takie pola.
- ID konta pobierane jest z powiązanego profilu. `SELECT ... FOR UPDATE` blokuje oba rekordy na czas transakcji edycji lub zmiany aktywności, a rola jest ponownie sprawdzana pod blokadą.
- E-mail sprawdzany jest przed zapisem, z wyłączeniem własnego konta podczas edycji. UNIQUE chroni również równoczesne zapisy; błąd 1062 staje się komunikatem przy polu e-mail. Niezmieniony zapis jest poprawny.

## Aktywność i historia

Lista pokazuje osobno `employees.active` (profil) oraz `users.active` (konto), także jeżeli stare dane mają rozbieżne statusy. Potwierdzone akcje „Aktywuj” i „Dezaktywuj” ustawiają **oba pola w jednej transakcji**. Ponowne wysłanie ustawia ten sam stan, zamiast go odwracać. Przy tworzeniu oba rekordy również otrzymują ten sam wybrany status.

Dezaktywacja blokuje nowe logowanie. Istniejący mechanizm `current_user()` sprawdza `users.active` przy każdym żądaniu chronionej strony, więc także już zalogowany pracownik traci dostęp przy kolejnym żądaniu. Aktywacja przywraca możliwość logowania dotychczasowym hasłem. Edycja danych ma osobny zakres i nie zmienia statusów.

Nie ma akcji DELETE — ani przez GET, ani POST. Wszystkie dane pozostają zachowane:

| Powiązanie | Istniejący FK | Zachowanie modułu |
|---|---|---|
| `employees.user_id → users.id` | DELETE RESTRICT | Konto i profil nie są usuwane. |
| `reservations.employee_id → employees.id` | DELETE RESTRICT | Wszystkie statusy i terminy rezerwacji pozostają bez zmian. |
| `employee_services.employee_id → employees.id` | DELETE CASCADE | Nie uruchamiamy kaskady; przypisania zostają. |
| `employee_availability.employee_id → employees.id` | DELETE CASCADE | Nie uruchamiamy kaskady; przedziały dostępności zostają. |

Dezaktywacja **nie anuluje zaplanowanych wizyt**. Ekran potwierdzenia wyjaśnia ten skutek. Zarządzanie wizytami oraz kontrola aktywności przy nowych rezerwacjach należą do dalszych zadań.

## Walidacja i zabezpieczenia

- Dane kontaktowe mają te same reguły co profil/rejestracja: imię do 50, nazwisko do 80 znaków Unicode, litery ze spacją/apostrofem/myślnikiem; poprawny e-mail do 254 bajtów; polski telefon 9 cyfr, opcjonalnie `+48`, spacje i myślniki. Telefon zapisywany jest w formacie `+48…`.
- Nowe hasło: minimum 12 znaków, maksimum 72 bajty, zgodne powtórzenie; bez trimowania. Formularze i odpowiedzi nie zawierają hasła ani hasha.
- Opcjonalny opis: do 2000 znaków Unicode, nowe wiersze i tabulatory dozwolone, pusty opis jako `NULL`. Wykorzystuje `validate_admin_text()` wydzielone ze wspólnej walidacji oferty. HTML pozostaje tekstem i jest escapowany przy wyświetlaniu.
- Odrzucane są tablice zamiast tekstu, błędne UTF-8, niedozwolone znaki sterujące, nadmierne długości i wymagane pola zawierające tylko białe znaki. Cały POST ma dotychczasowy limit 32 KiB.
- ID: dodatnie cyfry w zakresie UINT32, bez zer wiodących, notacji wykładniczej i ułamków; rekord musi istnieć. Dodatkowe ID i rola w POST nie są używane do zapisu.
- `require_role('admin')` chroni kontroler przed odczytem lub zapisem. Wszystkie zapytania używają istniejącego PDO i prepared statements; jedyny warunkowy fragment SQL to stałe `FOR UPDATE` ustalane wewnątrz modułu.
- Operacje zapisu wymagają POST i istniejącego CSRF; aktywacja/dezaktywacja także jawnego potwierdzenia. GET tylko wyświetla dane. Po sukcesie następuje przekierowanie 303 i flash.
- Dane HTML przechodzą przez istniejące `e()`. Nieobsłużone błędy trafiają do wspólnego bezpiecznego handlera. Nie dodano drugiego PDO, sesji, auth, CSRF, layoutu ani nawigacji.

## Pliki i testy

`includes/admin-employees.php` zawiera walidację i zapis, `public/admin-employees.php` jest kontrolerem, a `includes/admin-employees-view.php` dostarcza widok do wspólnego layoutu. Formularze korzystają z istniejących klas CSS; tabela ma własne szerokości kolumn i przewijanie w kontenerze na małym ekranie.

`tests/employees-http.php` jest uruchamiany przez istniejący `php tests/http.php` na osobnym losowym schemacie, zgodnie z [instrukcją testów](stage2-audit.md#powtarzanie-testów). Sprawdza role/URL, CSRF, potwierdzenia, błędne dane, XSS, manipulowanie rolą i ID, hashowanie, unikalność e-maila (także rzeczywisty błąd UNIQUE po nieaktualnym odczycie), paginację, brak fizycznego usuwania oraz zachowanie wszystkich zależności.

Testowe triggery wymuszają awarię drugiego zapisu przy tworzeniu, edycji i dezaktywacji, aby sprawdzić pełny rollback. Sprawdzane są też odmowa operacji na profilu z inną rolą, rozbieżne statusy starych danych, logowanie po aktywacji i utrata dostępu istniejącej sesji po dezaktywacji. Wspólne formularze kategorii i usług pozostają objęte testami regresji. Testy nie korzystają z lokalnej konfiguracji ani rzeczywistej bazy użytkownika.

## Wyniki końcowej weryfikacji — 5 listopada 2026

- PHP 8.4.27 i odizolowana MariaDB 11.4.5: wykonane 4 listopada **85 testów PHP i 697 kontroli HTTP** zakończyło się powodzeniem, bez pominięć. Końcowa kontrola nie wymagała zmian kodu aplikacji.
- Sprawdzono składnię wszystkich **47 plików PHP** oraz `git diff --check` — bez błędów.
- W Edge sprawdzono listę, dodawanie, edycję i oba potwierdzenia aktywności przy szerokościach **1280, 390 i 320 px**: łącznie **15 układów**. Brak poziomego przepełnienia strony, pola mają tekst co najmniej 16 px, a sprawdzone przyciski i akcje nawigacji wysokość co najmniej 44 px. Szeroka tabela przewija się we własnym kontenerze. Obejrzano zrzuty listy na komputerze i formularza na wąskim ekranie.
- W przeglądarce wykonano dodanie nowego pracownika, edycję danych, dezaktywację i ponowną aktywację. Oba statusy zmieniały się zgodnie z operacją. Pusty formularz z wyłączoną walidacją HTML pokazał sześć błędów PHP, z fokusem na komunikacie.
- Wylogowanie i cofnięcie w historii przeglądarki prowadziło do `/login.php`, bez ujawnienia danych administratora ani listy pracowników.
- Przegląd zmian nie wykazał nowych sekretów ani zmian SQL, ERD, konfiguracji PDO, sesji, autoryzacji lub CSRF. Nie dodano przypisywania usług ani zarządzania dostępnością.

Testy przeglądarkowe korzystały wyłącznie z tymczasowej kopii aplikacji i osobnego schematu. Nie sprawdzano rzeczywistej lokalnej bazy użytkownika, osobnego serwera MySQL, docelowego HTTPS ani fizycznego telefonu. Te ograniczenia nie oznaczają brakującej implementacji modułu. Uruchomienie we własnym środowisku nadal wymaga konfiguracji opisanej w README.

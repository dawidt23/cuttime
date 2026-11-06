# Usługi i dostępność pracownika — Etap 3

Moduły korzystają z bieżącego SQL, PDO, autoryzacji, sesji, CSRF, `e()`, flash i layoutu administratora. Schemat, ERD i dane testowe nie zmieniły się. Z listy `/admin-employees.php` wybierz „Usługi” lub „Dostępność”. Nawigacja główna prowadzi także bezpośrednio do dostępności. Obie strony bez parametru pracownika pokazują wybór osób z paginacją po 20.

## Przypisywanie N:M

`/admin-employee-services.php?employee_id=…` wyświetla wszystkie usługi jako checkboxy. Zaznaczenia pochodzą z `employee_services`. Tabela zachowuje złożony PK `(employee_id, service_id)` i istniejące FK. Imiona i opisy usług nie są kopiowane do przypisań.

Formularz zapisuje **cały wybrany zestaw**:

1. PHP sprawdza listę ID (do 500 pozycji), odrzuca nieprawidłowe typy/ID, usuwa duplikaty i sortuje wybór.
2. Transakcja blokuje rekord pracownika przez istniejące `employee_find(..., true)` i sprawdza rolę `employee` w powiązanym koncie.
3. Prepared SELECT z `LOCK IN SHARE MODE` sprawdza istnienie każdej wybranej usługi i chroni ją przed usunięciem do końca transakcji. Parametry `IN` są wiązane osobno.
4. Aplikacja odczytuje bieżące przypisania, usuwa tylko różnicę „było, ale odznaczono” i dodaje tylko „zaznaczono, ale nie było”. Wspólne elementy pozostają bez zmian. Całość zatwierdzana jest jednym COMMIT; awaria wycofuje również wcześniejsze usunięcia.

Pusty wybór usuwa wszystkie przypisania tej osoby. Zapis zawsze wymaga zaznaczenia potwierdzenia, POST i CSRF. Znacznik `_complete` umieszczony po checkboxach pozwala odrzucić formularz ucięty przez limit zmiennych PHP. Powtórzony zapis tego samego zestawu nie tworzy duplikatów. Gdy dwaj administratorzy zapiszą różne zestawy, operacje wykonają się kolejno, a ostatni poprawny zapis ustali zestaw — nie jest to scalanie starych formularzy.

Usługi i kategorie nieaktywne są oznaczone i można przygotować ich przypisania. Tak samo można przygotować ofertę nieaktywnego pracownika; operacja nie aktywuje konta, profilu ani usługi. Proces nowych rezerwacji musi w przyszłości uwzględnić wszystkie te statusy.

Nie są usuwane same usługi ani rezerwacje. `reservations` ma niezależne FK do `employees` i `services`, więc odpięcie usługi od pracownika zachowuje wcześniejsze wizyty, zgodnie z istniejącym schematem.

## Dostępność tygodniowa

`/admin-availability.php?employee_id=…` pokazuje wszystkie przedziały danej osoby, uporządkowane według dnia i początku. Można dodać, edytować i usunąć wpis. GET usuwania pokazuje potwierdzenie; usunięcie następuje dopiero po POST z CSRF i zaznaczonym potwierdzeniem.

Tabela `employee_availability` przechowuje **powtarzalny tydzień**, nie konkretne daty: `day_of_week` od 1 (poniedziałek) do 7 (niedziela). Każdy SELECT/UPDATE/DELETE pojedynczego wpisu zawiera zarówno ID wpisu, jak i zweryfikowane ID pracownika. ID przesłane dodatkowo w POST nie może zmienić celu operacji. Wpis innego pracownika otrzymuje 404.

Walidacja PHP:

- Dzień: wyłącznie jedna cyfra `1`–`7`.
- Godziny: dokładne `HH:MM` lub `HH:MM:SS`, od `00:00:00` do `23:59:59`. Minuty są normalizowane przez dopisanie `:00`; sekundy nie są obcinane. Formularz używa `type="time" step="1"`.
- Początek musi być wcześniejszy od końca w tym samym dniu. Niedozwolone są `24:00`, godziny ujemne, niepoprawne minuty/sekundy, przedziały nocne, puste wartości, spacje, tablice, HTML, ogromne teksty i inne nietypowe formaty.
- Kolizja zachodzi, gdy `istniejący.start < nowy.koniec` oraz `istniejący.koniec > nowy.start`, dla tego samego pracownika i dnia. W edycji pomijamy własne ID. Identyczny, częściowo zachodzący lub zawarty przedział zostaje odrzucony (409).
- Przedziały stykające się granicą są dozwolone. Inny dzień lub inny pracownik nie powoduje konfliktu. Niezmieniona edycja własnego wpisu jest poprawna.

Każdy zapis dostępności korzysta z tej samej blokady pracownika w transakcji. Zapobiega to wyścigowi dwóch zapisów także wtedy, gdy dany dzień nie ma jeszcze wpisów. Zapytanie o konflikt jest odczytem blokującym, więc po oczekiwaniu widzi zatwierdzony zapis poprzednika. Reguła dotyczy operacji modułu; bezpośrednie ręczne INSERT-y do SQL nie są chronione przed nakładaniem przez istniejący CHECK.

Zmiana lub usunięcie dostępności nie anuluje, nie przesuwa i nie usuwa rezerwacji. Interfejs wyraźnie o tym informuje. Nie wdrożono zarządzania rezerwacjami.

## Bezpieczeństwo i organizacja

- Oba kontrolery wywołują `require_role('admin')` przed obsługą danych. Dostęp przez ręczny URL oraz POST klienta/pracownika jest blokowany.
- `includes/admin-employee-relations.php` współdzieli kontekst wyboru osoby oraz transakcję i ponowne sprawdzenie roli pod blokadą. Nie tworzy nowego PDO ani systemu auth/CSRF.
- `includes/admin-employee-context-view.php` współdzieli wybór pracownika, komunikaty oraz przełączanie pomiędzy jego usługami i dostępnością. Dane pochodzą z bazy i są wyświetlane przez `e()`.
- `includes/admin-employee-services.php` i `includes/admin-availability.php` zawierają operacje bazowe i walidację. Odpowiednie pliki `*-view.php` odpowiadają za checkboxy, tabelę, formularz i potwierdzenia.
- Istniejący `admin_form_request()` sprawdza metody, limit POST 32 KiB i CSRF. Wszystkie ID mają format dodatniego UINT32, bez zer wiodących. Nie ufamy GET ani POST bez walidacji i sprawdzenia bazy.
- Wszystkie zapytania używają prepared statements. Oczekiwane konflikty mają czytelne komunikaty; nieobsłużone błędy przechodzą do istniejącego bezpiecznego handlera. Po sukcesie następują flash i przekierowanie 303.
- Lista pracowników otrzymała odnośniki do obu modułów. Wspólny CSS obsługuje checkboxy, wybór pracownika i tabelę przewijaną w kontenerze na małym ekranie.

## Testy

Uruchom `php tests/http.php` według [instrukcji środowiska testowego](stage2-audit.md#powtarzanie-testów). Zestaw dołącza `tests/employee-relations-http.php`, korzysta z własnej losowej bazy oraz tymczasowej kopii aplikacji i nie czyta lokalnej konfiguracji użytkownika.

Testy obejmują macierz ról i URL, CSRF, nieprawidłowe identyfikatory, podmianę pracownika, duplikaty i nieistniejące usługi, pusty wybór, częściowo przesłany formularz, transakcyjny rollback po błędzie przypisania, nieaktywne rekordy i XSS. Dostępność jest sprawdzana dla granic czasu, sekund, wszystkich rodzajów kolizji, stykających się przedziałów, niezmienionej edycji, usuwania i własności wpisu. Dwa osobne procesy PHP konkurują o ten sam przedział — tylko jeden może go zapisać. Sprawdzane jest też zachowanie wszystkich danych rezerwacji i usług.

### Wynik weryfikacji — 5 listopada 2026

- PHP 8.4.27 i odizolowana MariaDB 11.4.5: **85 testów PHP i 898 kontroli HTTP** zakończonych powodzeniem, bez pominięć. Równoczesny zapis sprawdzono dwoma procesami PHP i rzeczywistymi blokadami InnoDB: jeden sukces, jedna odmowa kolizji.
- Składnia wszystkich **56 plików PHP** poprawna; `git diff --check` bez błędów.
- Edge: **8 widoków × 3 szerokości (1280, 390 i 320 px)**, łącznie 24 układy. Brak poziomego przepełnienia strony; pola mają tekst co najmniej 16 px, sprawdzone przyciski, checkboxy z etykietami i akcje nawigacji mają obszar wysokości co najmniej 44 px. Obejrzano zrzuty formularza godzin, przypisań i tabeli.
- W przeglądarce zapisano kilka usług i pusty zestaw, dodano oraz edytowano przedział, sprawdzono komunikat kolizji i potwierdzane usunięcie. Komunikat błędu otrzymał fokus. Powrót po wylogowaniu prowadził do logowania bez danych panelu.
- Przegląd zmian nie wykazał nowych sekretów ani drugiego mechanizmu PDO/auth/CSRF. SQL, ERD i dane testowe pozostają bez zmian.

Testy uruchamiano wyłącznie na osobnym schemacie i tymczasowej kopii aplikacji. Nie testowano rzeczywistej lokalnej bazy użytkownika, osobnego MySQL, docelowego HTTPS ani fizycznego telefonu. Małe ekrany sprawdzono przez zmianę rozmiaru widoku przeglądarki.

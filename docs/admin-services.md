# Zarządzanie usługami — Etap 3

Moduł znajduje się pod `/admin-services.php`, w pozycji „Usługi” istniejącej nawigacji administratora. Wymaga standardowej konfiguracji PHP/PDO i konta `admin` zgodnie z [instrukcją uruchomienia](php-foundation.md). Używa istniejącej tabeli `services`, bez zmian SQL, ERD ani danych testowych.

## Działanie

- Lista pokazuje nazwę, ID, kategorię, cenę w złotych, czas w minutach, aktywność i akcje. Paginacja po 20 rekordów, sortowanie według nazwy i ID. Pełny opis jest dostępny w edycji.
- `?action=create` dodaje kategorię, nazwę, opis, cenę, czas trwania i aktywność usługi. Gdy nie ma kategorii, wyświetla odnośnik do jej dodania.
- `?action=edit&id=…` umożliwia edycję tych samych pól; niezmieniony zapis również jest poprawny. Nazwy usług nie mają w SQL `UNIQUE`, więc ich duplikaty są dopuszczalne; nie dodano sztucznego ograniczenia.
- `?action=activate&id=…` i `?action=deactivate&id=…` wyświetlają potwierdzenie, a dopiero POST z CSRF i zaznaczonym polem zmienia aktywność. Stan jest ustawiany jawnie, więc ponowne wysłanie nie odwraca go. Aktywność można też ustawić podczas zwykłej edycji i zapisać przyciskiem „Zapisz zmiany”.
- `?action=delete&id=…` pokazuje potwierdzenie lub blokadę z odnośnikiem do dezaktywacji. GET niczego nie zmienia. Usunięcie wymaga POST, CSRF i potwierdzenia.

Wszystkie poprawne operacje zapisu kończą się przekierowaniem 303 do listy i jednorazowym komunikatem. Nie dodano zarządzania pracownikami, dostępnością ani rezerwacjami.

## Walidacja PHP

| Pole | Reguła |
|---|---|
| Nazwa | Wymagana, 1–150 znaków Unicode, bez samych białych znaków i niedozwolonych znaków sterujących. |
| Opis | Opcjonalny, do 2000 znaków Unicode; dozwolone nowe wiersze i tabulatory. Pusty zapisuje się jako `NULL`. |
| Kategoria | Poprawne dodatnie ID oraz faktyczne istnienie rekordu sprawdzone przez prepared SELECT. Formularz `<select>` nie jest źródłem zaufania. |
| Cena | Od `0.00` do `99999999.99`, zgodnie z `DECIMAL(10,2)`. Akceptowana kropka lub przecinek i najwyżej 2 cyfry po separatorze. |
| Czas trwania | Całkowita liczba minut 1–1440. Limit doby jest ograniczeniem aplikacji; SQL pozostaje bez zmian. |
| Aktywność | Wyłącznie tekst `0` lub `1`; w osobnych akcjach wartość ustala serwer według operacji. |

Ceny są sprawdzane wyrażeniem regularnym i normalizowane do tekstu z dwiema cyframi po kropce. Nie używa się `float`, zaokrąglania ani obcinania nadmiernej precyzji. Odrzucane są wartości ujemne, notacja wykładnicza, `NaN`, `INF`, separatory tysięcy, tablice i przekroczenie zakresu. Zwykłe spacje na brzegach ceny są pomijane. Czas i ID wymagają zapisu cyframi bez zer wiodących, ułamków i notacji wykładniczej.

Teksty mają limit bajtów przed walidacją UTF-8 i długości. Cały POST ma limit 32 KiB. Dodatkowe ID w POST nie zmienia celu operacji: ID rekordu pochodzi ze zweryfikowanego URL. Błędne dane otrzymują czytelne komunikaty, bez SQL i szczegółów wyjątków.

Administrator może przypisać usługę również do istniejącej nieaktywnej kategorii, jawnie oznaczonej w formularzu i tabeli. Aktywność kategorii i usługi to osobne pola; moduł nie zmienia statusu kategorii. Jeśli kategoria zniknie między sprawdzeniem a zapisem, FK i obsługa błędu 1452 zwracają komunikat przy polu kategorii.

## Zależności i historia

- `services.category_id` → `service_categories.id`: obowiązkowa kategoria, FK `ON DELETE RESTRICT`.
- `reservations.service_id` → `services.id`: FK `ON DELETE RESTRICT`. **Każda rezerwacja**, również anulowana lub zakończona, blokuje fizyczne usunięcie usługi. Kontroler proponuje dezaktywację. Nowa rezerwacja dodana pomiędzy sprawdzeniem a DELETE nadal jest chroniona przez FK; błąd 1451 staje się czytelną odmową (409).
- `employee_services.service_id` → `services.id`: FK `ON DELETE CASCADE`. Usunięcie usługi bez rezerwacji usuwa również jej przypisania. Ekran potwierdzenia podaje ich liczbę i wyjaśnia skutek. Konta, profile i dostępność pracowników nie są usuwane.

Dezaktywacja aktualizuje wyłącznie `services.active`; zachowuje wszystkie rezerwacje i przypisania. Zmiana ceny lub czasu usługi nie modyfikuje rekordów rezerwacji ani godzin istniejących wizyt. Schemat nie przechowuje historycznej ceny usługi w rezerwacji — moduł nie dodaje takiego pola ani nie odtwarza dawnych cen.

## Organizacja i bezpieczeństwo

- `public/admin-services.php` wywołuje istniejące `require_role('admin')` przed każdym odczytem i zapisem; klient, pracownik i gość są blokowani także przez URL.
- `includes/admin-services.php` zawiera wyłącznie walidację specyficzną dla usługi i operacje na istniejącym PDO. Wszystkie zapytania używają prepared statements; limity i przesunięcia paginacji są wiązane jako liczby całkowite.
- `includes/admin-forms.php` współdzieli z kategoriami walidację tekstów, aktywności, ID, metod żądań, rozmiaru i wywołanie dotychczasowego CSRF.
- `includes/admin-offer-form.php` jest wspólnym formularzem kategorii i usług. `includes/admin-services-view.php` dostarcza tabelę i ekrany potwierdzeń do istniejącego layoutu. Używane są dotychczasowe style tabel/formularzy, z osobnymi szerokościami kolumn usług.
- Wszystkie dane konta, usług i kategorii w HTML przechodzą przez istniejące `e()`. Znaki HTML w tekstach są traktowane dosłownie. Ochrona XSS nie zastępuje parametrów SQL.
- Sesje, autoryzacja, logowanie, flash i obsługa błędów pozostają mechanizmami Etapu 2. Nieznane błędy PDO trafiają do wspólnego handlera.

## Testy

`php tests/http.php` uruchamia również nowy `tests/services-http.php`. Zestaw korzysta z własnego losowego schematu i kopii aplikacji, zgodnie z [instrukcją testów](stage2-audit.md#powtarzanie-testów), bez lokalnej konfiguracji użytkownika.

Scenariusze obejmują CRUD, wszystkie role i akcje, CSRF, potwierdzenia, puste/wadliwe dane, granice kwot i czasu, Unicode, XSS, poprawną oraz nieistniejącą kategorię, nieaktywną kategorię, paginację, ponowny zapis i brakujące rekordy. Sprawdzane są rzeczywiste błędy FK po wymuszeniu nieaktualnego wstępnego odczytu, zachowanie rezerwacji przy dezaktywacji i edycji, rezerwacja dodana po potwierdzeniu oraz usunięcie wyłącznie przypisań pracowników przez CASCADE. Cały dotychczasowy zestaw kategorii pozostaje testem regresji wspólnych formularzy i walidacji.

### Wynik weryfikacji — 4 listopada 2026

- PHP 8.4.27 i osobna, lokalna instancja MariaDB 11.4.5: wszystkie 85 testów PHP i 503 kontrole HTTP zakończyły się powodzeniem, bez pominiętych scenariuszy.
- Sprawdzono składnię wszystkich 43 plików PHP oraz `git diff --check` — bez błędów.
- W przeglądarce Edge sprawdzono 8 widoków przy szerokościach 1280, 390 i 320 px (24 układy), w tym formularz kategorii po wydzieleniu wspólnego kodu. Nie wystąpiło poziome przepełnienie strony; szeroka tabela przewija się we własnym kontenerze.
- Dodatkowo sprawdzono dodanie usługi, serwerowe błędy pustego formularza z wyłączoną walidacją HTML, dezaktywację i cofnięcie w historii przeglądarki po wylogowaniu. Dane panelu nie były dostępne po zakończeniu sesji.
- Kontrola zmian nie wykazała sekretów ani drugiego mechanizmu PDO, sesji, autoryzacji, CSRF lub escapowania HTML. SQL i ERD pozostały bez zmian.

Testy korzystały z izolowanej bazy i tymczasowej konfiguracji. Nie sprawdzano rzeczywistej lokalnej bazy użytkownika, osobnego serwera MySQL, docelowego HTTPS ani fizycznego telefonu. Uruchomienie aplikacji na innym komputerze nadal wymaga własnej konfiguracji bazy opisanej w README.

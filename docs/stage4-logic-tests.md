# CutTime — testy logiki Etapu 4

Weryfikacja na PHP 8.2.35 / MariaDB 11.4.5: **1544 zaliczone asercje, 0 błędów**. Wszystkie **93 pliki PHP** mają poprawną składnię. Test rzeczywistego zapisu przyszłej godziny dzisiaj wykonał się poprawnie, bez pominięcia.

## Znaleziony błąd i poprawka

Nowy INSERT prawidłowo blokował konflikty, ale administrator mógł przywrócić anulowaną wizytę do `pending`, `confirmed` lub `completed` bez sprawdzenia, czy inny klient zajął zwolniony termin. Dodany test HTTP najpierw potwierdził błąd: zamiast wymaganego 409 zapis przechodził.

Poprawiono istniejącą korektę statusu, bez nowego modułu i zmian schematu:

1. Odczyt pracownika rezerwacji, BEGIN i blokada jego rekordu `FOR UPDATE`.
2. Aktualny odczyt rezerwacji pod blokadą; ponowne sprawdzenie przypisania i oczekiwanego statusu formularza.
3. Przy przejściu ze statusu nieblokującego do blokującego — sprawdzenie konfliktu z innymi wizytami.
4. UPDATE wyłącznie statusu, COMMIT. Wczesny zwrot lub wyjątek powoduje ROLLBACK przez `finally`; błędy blokad dają kontrolowany komunikat 409.

`includes/reservation-conflicts.php` zawiera teraz jedno wspólne zapytanie konfliktu, używane przez tworzenie wizyty i przywracanie jej statusu. Lista statusów blokujących pozostaje w `includes/reservation-statuses.php`: `pending`, `confirmed`, `completed`. `cancelled` nie blokuje. Zmiana między już blokującymi statusami nie zajmuje nowego przedziału. Zapis klienta nadal pobiera dane z bazy i sprawdza przyszłość bezpośrednio przed INSERT.

## Przedziały

Dla istniejącej wizyty 10:00–11:00:

| Nowa wizyta | Oczekiwany i uzyskany wynik |
| --- | --- |
| 09:30–10:30 | BLOKADA |
| 10:00–11:00 | BLOKADA |
| 10:15–10:45 | BLOKADA |
| 10:30–11:30 | BLOKADA |
| 09:30–11:30 | BLOKADA |
| 09:00–10:00 | DOZWOLONE |
| 11:00–12:00 | DOZWOLONE |

Reguła to `new_start < existing_end AND new_end > existing_start`. Testy obejmują czystą funkcję przedziałów, generator dostępności, zapis w bazie oraz odpowiedzi HTTP. Zachowano wcześniejsze testy sekund SQL TIME i zmiany czasu trwania usługi między podsumowaniem a zapisem.

## Czasy trwania i godziny pracy

Sprawdzono rzeczywiste usługi oraz ich przypisania z danych testowych: strzyżenie męskie 30 minut (pracownik 2), modelowanie 45 minut, strzyżenie damskie 60 minut i koloryzację 120 minut (pracownik 1).

Dla każdej długości przetestowano przez HTTP: początek 15 minut przed otwarciem — 422; koniec 15 minut po zamknięciu — 422; start dokładnie przy otwarciu — poprawny zapis; koniec dokładnie przy zamknięciu — poprawny zapis. Sprawdzono zapisane `end_time`, właściciela i początkowy status mimo fałszywych wartości `duration`, `end_time`, `price`, `user_id` i `status` w formularzu.

## Przeszłość i walidacja

- Wczorajsza data — odrzucenie przez walidację daty i rzeczywisty POST.
- Dzisiejsza miniona godzina — odrzucenie przez generator i rzeczywisty POST.
- Godzina równa symulowanemu „teraz” — niedostępna.
- Przyszła godzina dzisiaj — oferowana i zapisywana przez rzeczywisty POST z testowym grafikiem.
- Przyszły dzień — dozwolony przy pozostałych spełnionych warunkach.

Testy czasowe mają dwa poziomy: deterministyczny zegar przekazany do istniejącego generatora oraz rzeczywiste żądania z czasem serwera. Nie dodano parametru pozwalającego przeglądarce zmienić zegar. Przy ponownym uruchomieniu tuż przed północą, gdy nie pozostaje pełen termin 15 minut, wyłącznie dodatkowy POST przyszłej godziny dzisiaj wypisze jawne SKIP; przypadek symulowany nadal jest obowiązkowy.

Przetestowano nieistniejące i nieaktywne usługi/pracowników, nieprawidłowe przypisanie N:M, tablice zamiast tekstu, bardzo długie dane, HTML/JavaScript, zmanipulowane ID/datę/godzinę i pola kontrolowane przez serwer. Sprawdzono również `reservation_id` i statusy podczas anulowania oraz obsługi wizyty. Negatywne żądania dostają kontrolowane odpowiedzi bez SQL/PDO/ścieżek/stosu. Pełny zestaw zawiera CSRF, brak potwierdzenia, niedozwolone metody i wygasłe formularze.

## Własność

Zestawy klienta i pracownika sprawdzają SQL ograniczający listy, szczegóły oraz operacje do właściciela ustalonego z sesji. Klient nie widzi i nie anuluje cudzej wizyty. Obaj pracownicy mogą obsługiwać własne wizyty, ale nie otwierają szczegółów ani nie zmieniają statusu drugiego. Dodatkowe `user_id`, `employee_id`, role i ID w GET/POST nie zmieniają tożsamości. Cudzy i nieistniejący rekord nie ujawniają danych.

## Równoczesność

Proces nadrzędny trzyma blokadę pracownika i uruchamia dwa osobne procesy PHP z niezależnymi połączeniami PDO oraz różnymi klientami. Oba zgłaszają gotowość przed zwolnieniem blokady. Następnie sprawdzane są oba wyniki i faktyczny stan bazy.

Przetestowane wyścigi:

- dwa identyczne terminy 10:00–11:00;
- częściowe nałożenie 10:00–11:00 oraz 10:30–11:30;
- zawieranie 10:00–12:00 oraz 10:15–11:00 (usługi o różnych długościach);
- nowa rezerwacja 10:30–11:30 kontra przywrócenie anulowanej 10:00–11:00.

Każdy wyścig dał **jednego zwycięzcę i jeden czytelny konflikt 409**. W trzech pierwszych przypadkach w bazie pozostała dokładnie jedna blokująca rezerwacja. Dla wyścigu z przywracaniem dodatkowe zapytanie łączące parami wizyty potwierdziło **zero aktywnych kolizji**. Wcześniejszy test dwóch jednakowych zapisów również nadal przechodzi.

To realna równoległość PHP/PDO, a nie dwa kolejne wywołania SELECT/INSERT. Test HTTP osobno sprawdza wejściową autoryzację i CSRF, a procesy równoległe wywołują te same funkcje zapisu. Odczyty `FOR UPDATE` widzą zatwierdzony wynik poprzednika nawet przy domyślnej izolacji REPEATABLE READ.

## Ponowne uruchomienie i bezpieczeństwo danych

Uruchom `php tests/http.php` z PDO MySQL oraz `CUTTIME_TEST_DSN`, `CUTTIME_TEST_USER`, `CUTTIME_TEST_PASSWORD` wskazującymi serwer testowy. Konto testowe musi móc tworzyć i usuwać własny schemat, co jest wymagane już przez istniejący zestaw. Żadne dane dostępowe nie zostały dopisane do repozytorium.

Runner tworzy losowy schemat `cuttime_http_<16 znaków hex>`, importuje tam SQL i uruchamia kopię aplikacji z osobną konfiguracją oraz sesjami. Nowy zestaw dodatkowo weryfikuje nazwę tego schematu. Dane scenariuszy są usuwane w `finally`, testowa usługa i grafik są odtwarzane/usuwane, a snapshot rezerwacji porównywany z początkowym. Cały schemat jest usuwany przez runner. Właściwa baza aplikacji nie była zmieniana.

## Pliki

- Nowe: `includes/reservation-conflicts.php`, `tests/stage4-logic-http.php`, `docs/stage4-logic-tests.md`.
- Zmienione: `includes/booking-save.php`, `includes/admin-reservations.php`, `tests/booking-save-worker.php`, `tests/http.php`.
- Zaktualizowana dokumentacja: `README.md`, `docs/booking-save.md`, `docs/admin-reservations.md`, `docs/booking-ux.md`.

Schemat `database/database.sql` pozostał bez zmian. Nie wykonywano git commit ani git push.

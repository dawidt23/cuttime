# CutTime — wybór pracownika, daty i godziny

Wybór terminu odbywa się na publicznym `/booking.php`. Samo przeglądanie terminów nie zmienia danych. Podsumowanie udostępnia klientowi formularz [bezpiecznego zapisu](booking-save.md) przez osobny endpoint POST.

## Przebieg

1. `/booking.php?service_id=…` ponownie odczytuje aktywną usługę i pokazuje wyłącznie aktywne profile pracowników (`employees.active = 1`), których konta są aktywne i mają rolę `employee`, oraz które są połączone z usługą przez `employee_services`.
2. Po wyborze pracownika `/booking.php?service_id=…&employee_id=…` sprawdza ten sam warunek N:M na serwerze i pokazuje formularz daty.
3. Po podaniu daty `/booking.php?service_id=…&employee_id=…&date=RRRR-MM-DD` sprawdza poprawność daty i zakaz przeszłości w strefie `Europe/Warsaw`, a następnie generuje dostępne godziny.
4. Po wyborze godziny `/booking.php?service_id=…&employee_id=…&date=…&time=GG:MM` jeszcze raz tworzy listę terminów na serwerze. Godzina spoza aktualnej listy jest odrzucona; poprawna prowadzi do podsumowania z formularzem potwierdzenia dla klienta.

Finalne potwierdzenie wymaga aktywnego konta klienta. Samo przeglądanie wolnych godzin pozostaje publiczne tak jak oferta.

## Wyznaczanie terminów

- Siatka ma krok **15 minut**. Jest to stała `BOOKING_SLOT_MINUTES`; pasuje do usług o długości 30, 45, 60 i 120 minut i pozwala wybrać termin w środku godziny.
- Dostępność jest odczytywana z `employee_availability` dla dnia tygodnia daty. Kandydat jest pokazany tylko wtedy, gdy całe `[start, start + duration]` mieści się w jednym przedziale pracy. Czas trwania pochodzi z `services.duration`, nigdy z adresu lub formularza.
- W wybranym dniu pobierane są rezerwacje pracownika o statusach `pending`, `confirmed` oraz `completed`. Lista `BOOKING_BLOCKING_STATUSES` jest jednym miejscem tej reguły. `cancelled` nie blokuje terminu.
- Konflikt jest wyznaczany jako `new_start < existing_end AND new_end > existing_start`. Dzięki temu częściowe i pełne nałożenie są blokowane, zaś termin kończący się dokładnie w chwili rozpoczęcia innego albo zaczynający się dokładnie po jego końcu jest dozwolony.
- W przypadku dzisiejszej daty nie są proponowane godziny, których początek już minął lub jest równy aktualnemu czasowi w Warszawie.

## Walidacja i bezpieczeństwo

`service_id` i `employee_id` przechodzą wspólną `record_id()`. Data ma dokładny format ISO i jest sprawdzana przez PHP, a godzina ma dokładny format `HH:MM`; oba parametry mogą być przesłane tylko jako pojedynczy tekst. Wszystkie zapytania korzystają z PDO prepared statements. Nieprawidłowe dane prowadzą do czytelnego komunikatu HTTP 400/404/422 bez SQL, wyjątków i ścieżek. Dane z bazy są wyświetlane przez `e()`.

Wybór terminu przez GET nie zmienia stanu. Finalny zapis wykonuje `/booking-save.php` przez POST z istniejącym zabezpieczeniem CSRF i ponowną walidacją w transakcji.

## Pliki

Nowe:

- `includes/booking.php` — kwalifikacje pracowników, walidacja daty/godziny, generowanie przedziałów i reguły konfliktów;
- `includes/booking-selection-view.php` — kolejne ekrany wyboru;
- `tests/booking-http.php` — testy relacji N:M, dat, grafików i kolizji;
- `docs/client-booking.md` — ten opis.

Zmodyfikowane:

- `includes/offer-page.php`, `includes/offer-view.php` — podłączenie kolejnych kroków pod istniejący layout oferty;
- `public/css/auth.css` — pracownicy, formularz daty i siatka godzin;
- `tests/offer-http.php`, `tests/http.php` — zgodność starego kroku i uruchamianie testów;
- `README.md`, `docs/client-offer.md` — aktualny zakres Etapu 4.

Nie zmieniono bazy danych, konfiguracji, sesji, autoryzacji, CSRF ani modułów administracyjnych.

<?php
 /**
 * Класс LangjiACController
 *
 * Предназначен для взаимодействия с кондиционерами Langji по протоколу Modbus RTU через TCP/IP соединение.
 * Предоставляет методы для чтения состояния, показаний датчиков и управления устройством.
 *
 * @property string $host IP-адрес устройства
 * @property int $port Порт устройства
 * @property int $timeout Таймаут соединения в секундах
 * @property mixed $unitId Идентификатор устройства Modbus (по умолчанию 1)
 */

class LangjiACController {
    private string $host;
    private int $port;
    private int $timeout = 5; // seconds
    private mixed $unitId = 1; // default Modbus unit ID

    public function __construct($host, $port, $unitId = 1) {
        $this->host = $host;
        $this->port = $port;
        $this->unitId = $unitId;
    }

    /**
     * Отправка запроса устройству по протоколу Modbus RTU через TCP
     *
     * @param int $functionCode Код функции Modbus
     * @param mixed $data Данные запроса
     * @return mixed Ответ устройства
     * @throws Exception При ошибках соединения или Modbus-исключениях
     */
    private function sendRequest(int $functionCode, mixed $data): string
    {
        // Создайте розетку TCP
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!$socket) {
            throw new Exception("Connection failed: $errno - $errstr");
        }

        // Подготовьте запрос
        $request = chr($this->unitId) . chr($functionCode) . $data;

        // Рассчитайте CRC (для Modbus RTU)
        $crc = $this->calculateCRC($request);
        $request .= chr($crc & 0xFF) . chr(($crc >> 8) & 0xFF);

        // Send request
        fwrite($socket, $request);
        error_log("Отправка запроса: " . bin2hex($request));
        // Читать ответ (с тайм-аутом)
        stream_set_timeout($socket, $this->timeout);
        $response = fread($socket, 256);
        fclose($socket);
        error_log("Получен ответ: " . bin2hex($response));
        // Проверьте, пуст ли ответ
        if (empty($response)) {
            throw new Exception("No response from device");
        }

        // Проверьте CRC
        $receivedCrc = ord($response[strlen($response)-1]) << 8 | ord($response[strlen($response)-2]);
        $calculatedCrc = $this->calculateCRC(substr($response, 0, -2));

        if ($receivedCrc !== $calculatedCrc) {
            throw new Exception("CRC check failed");
        }

        // Проверьте на исключение Modbus
        if ((ord($response[1]) & 0x80)) {
        $errorCode = ord($response[2]);
        $errors = [
            0x01 => 'Illegal function',
            0x02 => 'Illegal data address',
            0x03 => 'Illegal data value',
            0x04 => 'Slave device failure',
            0x06 => 'Slave device busy',
            0x0C => 'CRC check failure'
        ];
        throw new Exception("Ошибка Modbus: " . ($errors[$errorCode] ?? "Неизвестная ошибка ($errorCode)"));
    }

        return $response;
    }

    /**
     * Рассчитывает контрольную сумму CRC-16 для Modbus RTU
     *
     * @param string $data Данные для расчета CRC
     * @return int Контрольная сумма CRC
     */
    private function calculateCRC(string $data): int
    {
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]);

            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x0001) {
                    $crc >>= 1;
                    $crc ^= 0xA001;
                } else {
                    $crc >>= 1;
                }
            }
        }

        return $crc;
    }

    /**
     * Чтение статусных регистров (состояние работы устройства)
     *
     * @return array Массив статусов устройства
     * @throws Exception При ошибках чтения
     */
    public function readStatus($startAddress = 0x0000, $quantity = 10): array
    {
        $data = pack('n', $startAddress) . pack('n', $quantity);
        $response = $this->sendRequest(0x01, $data);

        return $this->extracted($response, $quantity, $startAddress);
    }

    /**
     * Чтение аварийных регистров (ошибки и предупреждения)
     *
     * @return array Массив аварийных состояний
     * @throws Exception При ошибках чтения
     */
    public function readAlarms($startAddress = 0x0000, $quantity = 32): array
    {
        $data = pack('n', $startAddress) . pack('n', $quantity);
        $response = $this->sendRequest(0x02, $data);

        return $this->extracted($response, $quantity, $startAddress);
    }

    /**
     * Чтение регистров датчиков (температуры, токи, влажность)
     *
     * @return array Массив показаний датчиков
     * @throws Exception При ошибках чтения
     */
    public function readSensors($startAddress = 0x0000, $quantity = 36): array
    {
        $data = pack('n', $startAddress) . pack('n', $quantity);
        $response = $this->sendRequest(0x03, $data);

        $byteCount = ord($response[2]);
        $sensorData = substr($response, 3, $byteCount);

        $result = [];
        for ($i = 0; $i < $quantity; $i++) {
            $offset = $i * 2;
            $value = unpack('n', substr($sensorData, $offset, 2))[1];

            // Скалирование значений
            $result[$startAddress + $i] = match ($startAddress + $i) {
                0x0000, 0x0001, 0x0006 => $this->toSignedInt($value) / 10.0,
                0x0002, 0x0003, 0x0004 => $value / 100.0,
                0x0005 => $value / 10.0,
                0x0023 => $value / 65280.0,
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Чтение конфигурационных регистров (настройки устройства)
     *
     * @return array Массив конфигурационных параметров
     * @throws Exception При ошибках чтения
     */
    public function readConfiguration($startAddress = 0x0000, $quantity = 21): array
    {
        $data = pack('n', $startAddress) . pack('n', $quantity);
        $response = $this->sendRequest(0x03, $data);

        $byteCount = ord($response[2]);
        $configData = substr($response, 3, $byteCount);

        $result = [];
        for ($i = 0; $i < $quantity; $i++) {
            $offset = $i * 2;
            $value = unpack('n', substr($configData, $offset, 2))[1];

            // Скалирование значений
            switch ($startAddress + $i) {
                case 0x0000: // Compressor starting temperature
                case 0x0001: // Compressor stop return difference temperature
                case 0x0002: // Heater starting temperature
                case 0x0003: // Heater stop return difference temperature
                case 0x0004: // Cabinet inside high temperature limit
                case 0x0005: // Cabinet inside low temperature limit
                    $result[$startAddress + $i] = $this->toSignedInt($value) / 10.0;
                    break;
                case 0x0006: // Dehumidification start humidity
                case 0x0007: // Dehumidification difference humidity stop return
                case 0x0008: // High humidity alarm value
                    $result[$startAddress + $i] = $value / 10.0;
                    break;
                case 0x000E: // Communication baud rate
                    $baudRates = [4800, 9600, 19200, 38400];
                    $result[$startAddress + $i] = $baudRates[$value] ?? $value;
                    break;
                case 0x0012: // High voltage alarm value
                case 0x0013: // Low voltage alarm value
                    $result[$startAddress + $i] = $value / 10.0;
                    break;
                default:
                    $result[$startAddress + $i] = $value;
            }
        }

        return $result;
    }

    /**
     * Запись в одиночный coil-регистр (управление реле)
     *
     * @param int $address Адрес регистра
     * @param bool $value Значение для записи
     * @return bool Успешность операции
     * @throws Exception При ошибках записи
     */
    public function writeSingleCoil(int $address, bool $value): bool
    {
        $data = pack('n', $address) . pack('n', $value ? 0xFF00 : 0x0000);
        $response = $this->sendRequest(0x05, $data);

        // Убедитесь, что ответ соответствует тому, что мы отправили
        $expectedResponse = chr($this->unitId) . chr(0x05) . $data;
        $expectedResponse .= chr($this->calculateCRC($expectedResponse) & 0xFF) . chr(($this->calculateCRC($expectedResponse) >> 8));

        if ($response !== $expectedResponse) {
            throw new Exception("writeSingleCoil не прошла проверку");
        }

        return true;
    }

    /**
     * Запись в одиночный holding-регистр
     *
     * @param int $address Адрес регистра
     * @param int $value Значение для записи
     * @return bool Успешность операции
     * @throws Exception При ошибках записи
     */
    public function writeSingleRegister(int $address, int $value): bool
    {
        $data = pack('n', $address) . pack('n', $value);
        $response = $this->sendRequest(0x06, $data);

        // Убедитесь, что ответ соответствует тому, что мы отправили
        $expectedResponse = chr($this->unitId) . chr(0x06) . $data;
        $expectedResponse .= chr($this->calculateCRC($expectedResponse) & 0xFF) . chr(($this->calculateCRC($expectedResponse) >> 8));

        if ($response !== $expectedResponse) {
            throw new Exception("writeSingleRegister не прошла проверку");
        }

        return true;
    }

    /**
     * Запись в несколько holding-регистров
     *
     * @param int $startAddress Начальный адрес регистров
     * @param array $values Массив значений для записи
     * @return bool Успешность операции
     * @throws Exception При ошибках записи
     */
    public function writeMultipleRegisters(int $startAddress, array $values): bool
    {
        $quantity = count($values);
        $byteCount = $quantity * 2;
        $data = pack('n', $startAddress) . pack('n', $quantity) . chr($byteCount);

        foreach ($values as $value) {
            $data .= pack('n', $value);
        }

        $response = $this->sendRequest(0x10, $data);

        // Убедитесь, что ответ соответствует тому, что мы отправили (только адрес и количество)
        $expectedResponse = chr($this->unitId) . chr(0x10) . pack('n', $startAddress) . pack('n', $quantity);
        $expectedResponse .= chr($this->calculateCRC($expectedResponse) & 0xFF) . chr(($this->calculateCRC($expectedResponse) >> 8));

        if (substr($response, 0, -2) !== substr($expectedResponse, 0, -2)) {
            throw new Exception("writeMultipleRegisters не прошла проверку");
        }

        return true;
    }

    /**
     * Преобразует 16-битное беззнаковое число в знаковое
     *
     * @param int $value Беззнаковое 16-битное число
     * @return int Знаковое число
     */
    private function toSignedInt(int $value): int
    {
        return $value > 32767 ? $value - 65536 : $value;
    }

    /**
     * Извлекает битовые значения из ответа
     *
     * @param string $response Ответ устройства
     * @param int $quantity Количество значений
     * @param int $startAddress Начальный адрес
     * @return array Массив извлеченных значений
     */
    private function extracted(string $response, mixed $quantity, mixed $startAddress): array
    {
        $byteCount = ord($response[2]);
        $alarmBytes = substr($response, 3, $byteCount);

        $result = [];
        for ($i = 0; $i < $quantity; $i++) {
            $byteIndex = (int)($i / 8);
            $bitIndex = $i % 8;
            $result[$startAddress + $i] = (ord($alarmBytes[$byteIndex]) >> $bitIndex) & 0x01;
        }

        return $result;
    }

    function convertIntToHex($decimalValue): string
    {
        $hexValue = dechex($decimalValue); // Преобразуем в шестнадцатеричное
        // Если нужно добавить префикс "0x" и дополнить нулями до 4 символов:
        return '0x' . str_pad($hexValue, 4, '0', STR_PAD_LEFT);
    }

    function convertBoolToHex($bool): int
    {
        return $bool ? 0xFF00 : 0x0000;
    }

    function echoFullResponse($sensors)
    {
        $result = [];
        foreach ($sensors as $address => $value) {
            $description = match ($address) {
                0x0000 => "Температура внутреннего датчика температуры 1",
                0x0001 => "Температура внешнего датчика температуры 1",
                0x0002, 0x0003, 0x0004,0x0005, 0x0007 => "Резервное значение",
                0x0006 => "Уровень влажности",
                0x0008 => "Начальная температура охлаждения",
                0x0009 => "Порог остановки охлаждения",
                0x000A => "Порог начала обогрева",
                0x000B => "Порог остановки производства тепла",
                0x000C => "Начальная температура тепловой трубки",
                0x000D => "Конечная температура тепловой трубки",
                0x000E => "Порог срабатывания тревоги высокой температуры",
                0x000F => "Порог срабатывания тревоги низкой температуры",
                0x0010 => "Порог начала осушения по влажности",
                0x0011 => "Порог остановки осушения по влажности",
                0x0012 => "Температура калибровки датчика температуры 1",
                0x0013 => "Температура калибровки датчика температуры 2",
                0x0014 => "Настройка тревоги давления",
                0x0015 => "Включение настройки чувствительности температуры 1",
                0x0016 => "Включение настройки чувствительности температуры 2",
                0x0017 => "Включение настройки датчика влажности",
                0x0018 => "Настройка режима компрессора",
                0x0019 => "Настройка режима электрического нагрева",
                0x001A => "Настройка режима внутреннего вентилятора",
                0x001B => "Настройка режима внешнего вентилятора",
                0x001C => "Настройка сбоя датчика температуры 1",
                0x001D => "Настройка сбоя датчика температуры 2",
                0x001E => "Настройка сбоя по влажности",
                0x001F => "Настройка сбоя высокотемпературной тревоги",
                0x0020 => "Настройка сбоя низкотемпературной тревоги",
                0x0021 => "Настройка сбоя тревоги давления",
                0x0022 => "Настройка сбоя тревоги замерзания",
                0x0023 => "Переключение системы (контроллера) On/Off",
                default => "Неизвестный параметр"
            };

            // Форматируем вывод
            $result[] = [
                'Адрес' => sprintf('0x%04X', $address),
                'Описание' => $description,
                'Значение' => $value
            ];
        }

        // Выводим результат
        foreach ($result as $item) {
            $dec = hexdec($item['Адрес']);
            echo "{$item['Адрес']}($dec),{$item['Описание']}, Значение: {$item['Значение']}\n";
        }
    }
}



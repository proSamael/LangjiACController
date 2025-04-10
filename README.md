```PHP Class LangjiACController```

Предназначен для взаимодействия с кондиционерами Langji по протоколу Modbus RTU через TCP/IP соединение.  
Предоставляет методы для чтения состояния, показаний датчиков и управления устройством.  

## FAQ

### Как настроить подключение?
```PHP
include_once 'LangjiACController.php';

try {
    // Инициализировать
    $host = '100.64.16.23'; // IP-адрес устройства
    $port = 8000; // Порт устройства
    $unitId = 1; // Идентификатор блока Modbus (обычно 1)
    
    $ac = new LangjiACController($host, $port, $unitId);
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
```

### Как считать показатели?

#### Читаем все показатели
```PHP
    $sensors = $ac->readSensors();
    $ac->echoFullResponse($sensors);
```

#### Показать определенный показатель
```PHP
    $sensors = $ac->readSensors();
    echo "Температура обратного воздуха: " . $sensors[0x0000] . " °C\n";
```

#### Считать диапазон позазателей

startAddress - начальный адрес диапазона  
quantity- кол-во параметров(глубина)  
```PHP
    $sensors = $ac->readSensors(startAddress: 0x0023, quantity: 1);
```

#### Включение\Выключение устроства
```PHP    
    // Включить устройство
    $ac->writeMultipleRegisters(0x0023, [$ac->convertBoolToHex(true)]);
    // Выключить устройство
    $ac->writeMultipleRegisters(0x0023, [$ac->convertBoolToHex(false)]);
```

#### Установить параметры

```PHP    
    // Установить температуру отключения охлаждения
    $ac->writeMultipleRegisters(0x0008, [300]);
    // Установить порог остановки охлаждения
    $ac->writeMultipleRegisters(0x0009, [240]);
```


```SNR-ACC-500-ACH-RU-FULL.mbp``` - файл конфигурации(на русском) для ModBus Poll 
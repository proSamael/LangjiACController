<?php
include_once 'LangjiACController.php';

try {
    // Инициализировать
    $host = '100.64.16.23'; // IP-адрес устройства
    $port = 8000; // Порт устройства
    $unitId = 1; // Идентификатор блока Modbus (обычно 1)

    $ac = new LangjiACController($host, $port, $unitId);
    // Включить устройство
    //$ac->writeMultipleRegisters(0x0023, [$ac->convertBoolToHex(true)]);
    // Выключить устройство
    //$ac->writeMultipleRegisters(0x0023, [$ac->convertBoolToHex(false)]);
    // Установить температуру отключения охлаждения
    //$ac->writeMultipleRegisters(0x0008, [300]);
    // Установить порог остановки охлаждения
    //$ac->writeMultipleRegisters(0x0009, [240]);
    //$sensors = $ac->readSensors();
    //$sensors = $ac->readSensors(quantity: 25);
    //$sensors = $ac->readSensors(startAddress: 0x0023,quantity: 1);
    // echo "Данные датчиков:\n";
    // echo "Температура обратного воздуха: " . $sensors[0x0000] . " °C\n";
    //$ac->echoFullResponse($sensors);

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
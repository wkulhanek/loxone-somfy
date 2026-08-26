# Somfy Plugin — Development Updates

## 2026-08-26: Command Queue (Thread-Safety Fix)

The MQTT message callback previously called `_execute_command` directly and synchronously. The TaHoma local API call inside `_execute_command` has a 15-second timeout; under a slow or busy gateway this blocked the paho MQTT network thread, preventing keepalive packets from being sent and causing spurious disconnects and reconnection loops.

### Fix

Added a `queue.SimpleQueue` + dedicated worker thread (mirroring the pattern used in loxone-smartmii):

- `_on_mqtt_message` now puts `(dev_id, command)` tuples onto `self._cmd_queue` instead of calling `_execute_command` directly.
- A `_cmd_worker` thread (started as a daemon thread in `run()`) consumes from the queue and calls `_execute_command`. The MQTT callback returns immediately.
- Added `import queue` and `import threading` to imports.

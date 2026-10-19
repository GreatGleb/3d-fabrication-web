# Запуск Codex (D-28)

[← D-28](../decisions.md#d-28). Под учётной записью прогона (D-39: сессия `sudo machinectl shell aiqme-run@`), без ключей и токенов push к GitHub:

```bash
cd /home/aiqme-run/3D_website          # корень копии; Codex может работать и из aiqme-site/
codex exec --sandbox danger-full-access "<задание оркестратора>"
```

- Флаги `--sandbox read-only | workspace-write | danger-full-access` у `codex exec` сверены 19.10 с developers.openai.com/codex (справочник CLI). Перед первым запуском сверить синтаксис с `codex exec --help` установленной версии; `-a never` — глобальный флаг, его применимость к `exec` проверить там же.
- `aiqme-site/.codex/config.toml` (Boost MCP) Codex берёт только при запуске из `aiqme-site/` и если проект доверенный (`trust_level = "trusted"` в `~/.codex/config.toml`); без него задачи выполнимы (W-01).
- Ограничения D-20 и `rules` tasks.json действуют в любом режиме.
- Альтернатива (выбор пользователя): `--sandbox workspace-write` с разрешённой сетью, прогретыми кэшами Composer/npm и проверенным доступом к Docker.

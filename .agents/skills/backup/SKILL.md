---
name: backup
description: 'Back up TXT files in the project arts folder. Use when the user explicitly requests a backup.'
---


## Когда применять
- При явном запросе на резервное копирование файлов `arts` или когда пользователь отдельно просит сделать копию перед изменением этих файлов.

## Правило (уже принятое, не обсуждается)
Копировать TXT-файлы из папки `arts` в корне проекта в папку `backup` в корне проекта. Если папка `backup` не существует, создать ее.
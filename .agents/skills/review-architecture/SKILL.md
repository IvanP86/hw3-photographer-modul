---
name: review-architecture
description: 'Review implementation against architecture decisions and diagrams. Use when checking code, ADRs, schemas, pull requests, or Mermaid diagrams for inconsistencies.'
---

## Procedure

1. Прочитай diff и связанные документы.
2. Отдели текущее поведение от целевого.
3. Для расхождений укажи файлы и факт.
4. Предложи правку и способ проверки.
5. Верни замечания. По умолчанию файлы не меняй; если пользователь прямо просит исправить найденные расхождения, внеси только эти исправления.

**На выходе:** Замечания с доказательствами; правки — только если пользователь явно попросил их внести.
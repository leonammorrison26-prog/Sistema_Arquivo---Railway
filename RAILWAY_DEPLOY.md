# Deploy no Railway

Este projeto agora esta em PHP/HTML/CSS/JS.

## Start

O Railway usa:

```bash
php -S 0.0.0.0:$PORT index.php
```

## Dados persistentes

Crie um Volume no Railway para manter:

- `banco_diarq.db`
- `planilhas/`

O sistema usa automaticamente `RAILWAY_VOLUME_MOUNT_PATH` quando o volume estiver montado.
Se o volume estiver vazio, o sistema copia o `banco_diarq.db` da raiz para o volume na primeira inicializacao.

## Variaveis obrigatorias

- `SUPABASE_URL`
- `SUPABASE_KEY` ou `SUPABASE_ANON_KEY`

As gravacoes de acervo, usuarios e indicadores tentam salvar primeiro no Supabase. Se o Supabase falhar, a gravacao local e interrompida.

O schema antigo ja tem `inventario` e `usuarios`. Para indicadores, aplique tambem:

- `supabase/migrations/20260615213000_add_indicadores.sql`

## Variaveis opcionais

- `DATA_DIR`: caminho alternativo para dados locais.
- `OPENAI_API_KEY`: ativa o assistente virtual.
- `OPENAI_MODEL`: modelo do assistente, padrao `gpt-4.1-mini`.

## Excel e PDF

O Railway instala as bibliotecas via Composer:

- `phpoffice/phpspreadsheet` para exportacao `.xlsx`
- `setasign/fpdf` para Etiqueta e Guia Fora em PDF

## Primeiro login

- Usuario: `ADMIN`
- Senha: `admin`

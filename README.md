# Módulo Nota Fiscal para WHMCS via NFE.io

[![NFE.io](https://nfe.github.io/whmcs-addon/assets/img/nfeio-whmcs-notas-fiscais.png)](https://nfe.io)

> **Automatize a emissão de notas fiscais de serviço (NFS-e) no seu WHMCS com a NFE.io!**

O [**Módulo NFE.io para WHMCS**](https://nfe.io/docs/integracoes/plugins/whmcs/) é a solução definitiva para automatizar a conformidade fiscal do seu negócio de hospedagem e serviços digitais. Elimine tarefas manuais, reduza erros e garanta que seus clientes recebam suas notas fiscais pontualmente.

## 🚀 Por que usar este módulo?

*   **Automação Completa:** Emita notas fiscais automaticamente quando uma fatura é paga.
*   **Economia de Tempo:** Deixe o sistema trabalhar por você e foque no crescimento do seu negócio.
*   **Conformidade Fiscal:** Suporte completo à **Reforma Tributária Brasileira** (v3.1.0+), incluindo campos NBS, Classificação Tributária e Indicador de Operação.
*   **Multiempresas:** Gerencie múltiplos emissores e CNPJs em uma única instalação do WHMCS.
*   **Flexibilidade:** Configure códigos de serviço personalizados por produto e alíquotas específicas.

## ✨ Principais Funcionalidades

*   [x] **Emissão Automática:** Geração de NFS-e após confirmação de pagamento.
*   [x] **Emissão Manual:** Possibilidade de emitir notas avulsas ou reemitir com um clique.
*   [x] **Cancelamento Automático:** Cancela a nota fiscal se a fatura for cancelada/reembolsada (configurável).
*   [x] **Envio por E-mail:** Envia o PDF e XML da nota diretamente para o cliente.
*   [x] **Validação Inteligente:** Valida CPF/CNPJ antes da emissão para evitar erros.
*   [x] **Logs Detalhados:** Histórico completo de comunicação com a API para fácil diagnóstico.
*   [x] **Fila de Processamento:** Evita sobrecarga e garante a entrega das requisições.

## 📚 Documentação e Instalação

Para guias detalhados de instalação, configuração e uso, acesse nossa documentação oficial:

*   📖 **[Guia de Instalação](https://nfe.io/docs/plugins/whmcs/instalacao/)**
*   ⚙️ **[Manual de Configuração](https://nfe.io/docs/plugins/whmcs/configuracao/)**

## 🛠️ Requisitos do Sistema

*   **WHMCS:** Versão 8.0 ou superior
*   **PHP:** Versão 7.2 ou superior
*   **Conta NFE.io:** Uma conta ativa na [NFE.io](https://nfe.io) com chave de API.

## 📦 Changelog

Fique por dentro das novidades e atualizações do módulo.

*   [Acesse o Changelog Completo](CHANGELOG.md)

## Atualização do fork para 3.4.0

Base oficial: `42381cd1371a9179b81e51c5a2e64f0ffef876c8` (3.4.0), incluindo a correção de autorização da 3.3.2. As alterações locais de produção foram registradas separadamente antes da integração.

Preservados: competência baseada no pagamento, mapeamento fiscal condicional, monitoramento de pagamentos até o dia anterior, consulta do estado remoto, retries do Telegram e prioridade das dependências do WHMCS no autoload. Nenhum segredo integra esta atualização.

A versão oficial corrige o tratamento de crédito, Mass Pay e adição de fundos; não contém uma correção confirmada para notas presas em `WaitingReturn`. Os testes abaixo são isolados e não emitem notas, acessam banco ou enviam mensagens:

```sh
php tests/invoice-paid-regression.php
php tests/monitor-autoload-regression.php
```

Antes do deploy: backup completo do addon fora de `public_html`, validação com o PHP da produção e aprovação do responsável. Esta atualização não requer migração de banco nem substituição de `vendor`. A validação isolada não substitui a conferência do cron e dos logs após o deploy.

## 🤝 Suporte

Precisa de ajuda?

*   Abra uma [Issue no GitHub](https://github.com/nfe/whmcs-addon/issues) para reportar bugs ou sugerir melhorias.
*   Entre em contato com o suporte da NFE.io para dúvidas sobre a API ou sua conta.

---

<p align="center">
  Desenvolvido com ❤️ pela equipe <a href="https://nfe.io">NFE.io</a>
</p>

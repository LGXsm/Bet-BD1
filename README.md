# Bet BD1

Jogo de perguntas e apostas em equipes para uma apresentação de Banco de Dados. Os participantes entram com nome e equipe; o criador controla as perguntas, a pontuação e a partida. A versão desta pasta é a usada na hospedagem HostGator.

## Arquivos

- `src/index.html`: interface do jogo.
- `src/question-bank.json`: banco inicial de perguntas.
- `hostgator/api.php`: regras, partidas e painel do criador.
- `hostgator/build.py` e `hostgator/deploy.py`: preparação e envio por FTPS passivo.

## Hospedagem

A configuração e os passos de implantação estão em [hostgator/README.md](hostgator/README.md). Copie `hostgator/.env.example` para `hostgator/.env` e preencha suas credenciais localmente. O arquivo `.env`, o código do criador, o estado das partidas e os dados dos participantes não fazem parte deste repositório.

Para preparar os arquivos sem enviá-los, execute `python hostgator/deploy.py --dry-run`. Para atualizar apenas o código e preservar os dados no servidor, execute `python hostgator/deploy.py --code-only`.

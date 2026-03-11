# capstone3750

## Project overview
A multiplayer web app Battleship game.
## Architecture summary
The [backend](https://github.com/SStamper-Dev/capstone3750) is PHP for managing the server's API endpoints. It connects to a MySQL database for storing player, ship, and game information. The [frontend](https://github.com/SStamper-Dev/warship) is HTML/CSS and JavaScript for handling user interaction and sending it to the server.
## API description
### Production Endpoints
* POST   /api/reset - clears all tables
* POST   /api/players - creates a new player with specified username in json body
* GET    /api/players/{id}/stats - gets the stats of the player specified in path param
* POST   /api/games - create a game from a json body containing creator_id, grid_size, and max_players
* POST   /api/games/{id}/join - join a player specified in the json body to the game specified in the path param
* GET    /api/games/{id} - gets statistics of the game specified in path param
* POST   /api/games/{id}/place - places ships in specified game from path param using a json body
* POST   /api/games/{id}/fire - player specified in json body fires at coordinates specified in json body within the game specified in path param
* GET    /api/games/{id}/moves - gets a list of moves made within the game specified in path param
### Test Mode Endpoints (require test mode to be active and a password)
* POST   /api/test/games/{id}/restart - restarts game specified in path param
* POST   /api/test/games/{id}/ships - places ships in specified game from path param using a json body
* GET    /api/test/games/{id}/board/{player_id} - gets what the board looks like for the specified game and player, both from path params
## Team member names
* Justin Hooker
* Seth Stamper
## AI tool(s) used
* Claude
## Major role of each human + AI
* Justin Hooker - PHP backend/API endpoints
* Seth Stamper - Frontend visual design, JavaScript, and database/table schema
* Claude - A PHP file outlining the endpoints, some refactoring, and assistance in some endpoint implementation. 
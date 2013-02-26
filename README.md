harry_botter
====================

harry_botter is a simple IRC bot written in PHP. No claim of quality is made, as this was an early project. The code is nasty and full of kludge, but works.


### Features
* automatic opping, hopping, voicing, and kicking
* stores user data in a sql database
* !seen command to show last time a user was in the channel and said something
* various commands for generating string checksums and hashes
* stores list of online users in a database for easy querying for a web page

### Web Page Integration
harry_botter uses a MySQL database to store information about the users it sees in a channel. The database can be easily queried by a web page to show the current users in the channel.

A multithreaded image processing utility. This tool handles images dynamically and you can add an unlimited number of servers as well.

You can have a master server that all servers request data from and then each server can have this script to process images utlizing all the power of your server.

Configurations can be adjusted in `config/settings.json` which has the following values

`threads` number of threads to run (default: `50`)

`init` initial records to start with (default: `2K`)

`more_after` request more records if the queue is lower than

`more_to_fetch` number of records to fetch after the first request

`callback_after` update the main server after processing

**How to run**
`php process.php` is all you need to run the processes. You'll need to adjust the code to fit your needs since this wasn't intended to be a public use library.

Make sure to read files under `/model` directory as they are the most important ones.

Create and issue or send me an email for any questions

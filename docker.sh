docker build -t 'wxwclub:worker' .

docker run -d --restart always -v $(pwd):/wxwClub \
--name wxwclub_worker wxwclub:worker /wxwClub/cli.php worker

#!/bin/bash
# fetch the latest git log for the webui

val=`cd /usr/local/pia ; /usr/local/bin/git fetch origin &> /dev/null ; /usr/local/bin/git rev-list HEAD... origin/"$1" --count 2>/dev/null`

if [ "$val" = "0" ]; then
  dt=`date +%s`
  echo "$dt|$val" > /usr/local/pia/cache/webui-update_status.txt

  if [ ! -f "/var/www/pia_latest_changes.md" ]; then
    #fetch latest changelog - updated installations without the file
    cd /tmp
    mkdir piatmpget ; cd /tmp/piatmpget
    /usr/local/bin/wget http://www.kaisersoft.net/pia_latest_changes.md
    mv pia_latest_changes.md /usr/local/www/apache24/data/pia_latest_changes.md
    cd /tmp ; rm -rf /tmp/piatmpget
  fi

elif [ "$val" -gt "0" ]; then
  dt=`date +%s`
  echo "$dt|$val" > /usr/local/pia/cache/webui-update_status.txt

  #fetch latest changelog
  cd /tmp
  mkdir piatmpget ; cd /tmp/piatmpget
  /usr/local/bin/wget http://www.kaisersoft.net/pia_latest_changes.md
  mv pia_latest_changes.md /usr/local/www/apache24/data/pia_latest_changes.md
  cd /tmp ; rm -rf /tmp/piatmpget

else
  echo -e "[\e[1;31mfail\e[0m] "$(date +"%Y-%m-%d %H:%M:%S")\
    "- invalid information returned by git .... "\
    "$val"
fi

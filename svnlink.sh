# Repo locations
GITDIR=/Users/ksemel/Developer/workspace/github.com/ksemel/network-plugin-auditor
SVNDIR=${GITDIR}/svnrepo
SVNPATH=http://plugins.svn.wordpress.org/network-plugin-auditor

# Last info pushed to git
LASTGITMSG=`git log -1 --pretty=%B`
LASTGITTAG=`git describe --abbrev=0 --tags`
LASTSVNMSG=`cd ${SVNDIR};svn log -l 1 trunk | perl -nwle 'print unless m/^((r\d)|(-)|($))/';cd ${GITDIR};`

# Only run the rest of this if the last commit in SVN's trunk doesn't match the last git commit
# This ensures we only push new revisions to SVN
if [ "${LASTGITMSG}" != "${LASTSVNMSG}" ]; then
	# Copy files from the git repo to the svn repo
	cp ${GITDIR}/network-plugin-auditor.php ${SVNDIR}/trunk/network-plugin-auditor.php
	cp ${GITDIR}/readme.txt ${SVNDIR}/trunk/readme.txt

	# Move to the svn repo dir
	cd ${SVNDIR}

	# Reset the svn repo
	svn revert --recursive .
	# Get the latest code
	svn update

	# Commit the files to the trunk
	echo "svn commit -m \"${LASTGITMSG}\""
	svn commit -m "${LASTGITMSG}"

	# Create a tag from the files in trunk
	echo "svn copy ${SVNPATH}/trunk/ ${SVNPATH}/tags/${LASTGITTAG} -m \"Tagging ${LASTGITTAG}\""
	svn copy ${SVNPATH}/trunk/ ${SVNPATH}/tags/${LASTGITTAG} -m "Tagging ${LASTGITTAG}"

	# Back to the git dir at the end
	cd ${GITDIR}
else
	echo 'No new commits in git'
fi
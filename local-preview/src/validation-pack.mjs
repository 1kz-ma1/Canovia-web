// New original workflow fixtures. No IPA/AP80 or third-party question copied.
// Human publication review has NOT occurred. Release build must refuse this pack.
export const pack={id:'canovia-original-validation',version:1,review_status:'validation_only',source_type:'original_workflow_fixture',title:'動作確認用・基礎クイズ',questions:[
 {id:'binary-two',topic:'数の基礎',prompt:'2進数の10を10進数で表すと？',choices:{a:'1',b:'2',c:'10'},answer:'b',explanation:'2進数の10は、1×2＋0×1なので2です。'},
 {id:'binary-three',topic:'数の基礎',prompt:'2進数の11を10進数で表すと？',choices:{a:'2',b:'3',c:'11'},answer:'b',explanation:'1×2＋1×1なので3です。'},
 {id:'binary-four',topic:'数の基礎',prompt:'10進数の4を2進数で表すと？',choices:{a:'10',b:'11',c:'100'},answer:'c',explanation:'4は2の2乗なので、2進数では100です。'},
 {id:'logic-and',topic:'論理の基礎',prompt:'AとBが両方とも真のとき、A AND Bは？',choices:{a:'真',b:'偽',c:'判断できない'},answer:'a',explanation:'ANDは両方の条件が真のときだけ真になります。'},
 {id:'logic-or',topic:'論理の基礎',prompt:'Aが偽、Bが真のとき、A OR Bは？',choices:{a:'偽',b:'真',c:'判断できない'},answer:'b',explanation:'ORは少なくとも一方が真なら真になります。'},
 {id:'logic-not',topic:'論理の基礎',prompt:'Aが真のとき、NOT Aは？',choices:{a:'真',b:'偽',c:'判断できない'},answer:'b',explanation:'NOTは真と偽を反転するため、偽です。'}
]};

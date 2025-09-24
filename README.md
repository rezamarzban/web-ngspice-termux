# web-ngspice-termux
Web PHP based GUI for ngspice in termux

Sample SPICE netlist for ngspice:

```
* Astable multivibrator (BJT) — ngspice with CSV export
* Minimal, parameterized, no plot, single signal output

.param VCC=5 Rc=10k Rb=100k Rbg=100k C=10n

V1 vcc 0 {VCC}

Rc1 vcc c1 {Rc}
Rc2 vcc c2 {Rc}

Rb1 vcc b1 {Rb}
Rb2 vcc b2 {Rb}
Rbg1 b1 0 {Rbg}
Rbg2 b2 0 {Rbg}

C1 c1 b2 {C}
C2 c2 b1 {C}

Q1 c1 b1 0 QNPN
Q2 c2 b2 0 QNPN

.model QNPN NPN( BF=200 VAF=100 IS=1e-15
+ Cje=10p Cjc=5p TF=0.1n TR=50n )

.ic V(c1)={VCC} V(c2)=0 V(b1)=0 V(b2)=0

.tran 1u 20m
.save all

.control
  run
  set filetype=ascii
  wrdata sim.csv v(c1) v(c2) v(b1) v(b2)
.endc

.end
```
